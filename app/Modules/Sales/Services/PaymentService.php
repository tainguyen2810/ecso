<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Services\CashAccountService;
use App\Modules\Cashbook\Support\PeriodLock;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\CashShift;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments and refunds of manual orders. The order totals (paid_amount, refunded_amount, payment_status) are always
 * recomputed from the payment rows under the order row lock, so they cannot drift.
 */
class PaymentService
{
    public function __construct(private readonly AuditLogger $audit, private readonly CashAccountService $accounts) {}

    /**
     * @param  array{kind?: string, method: string, amount: string|int|float, reference?: string|null, note?: string|null, paid_at?: string|null, order_return_id?: int|null, account?: string|null}  $data
     */
    public function record(Order $order, array $data): OrderPayment
    {
        return DB::transaction(function () use ($order, $data) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isManual()) {
                throw ValidationException::withMessages(['order' => 'Đơn sàn do sàn thu tiền, không ghi nhận thanh toán ở đây.']);
            }

            $kind = $data['kind'] ?? OrderPayment::PAYMENT;
            $amount = $this->money($data['amount']);
            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0.']);
            }

            if ($kind === OrderPayment::PAYMENT) {
                if ($order->status === OrderStatus::CANCELLED) {
                    throw ValidationException::withMessages(['order' => 'Đơn đã hủy, không thu tiền.']);
                }
                $due = $order->balanceDue();
                if (bccomp($amount, $due, 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Số tiền vượt quá số còn phải thu ('.number_format((float) $due, 0, ',', '.').' đ).']);
                }
            } else {
                $refundable = bcsub((string) $order->paid_amount, (string) $order->refunded_amount, 2);
                if (bccomp($amount, $refundable, 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Số tiền hoàn vượt quá số khách đã trả ('.number_format((float) $refundable, 0, ',', '.').' đ).']);
                }
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
            PeriodLock::assertOpen($paidAt);

            $payment = new OrderPayment;
            $payment->forceFill([
                'cash_account_id' => $this->accounts->resolve($data['account'] ?? null, $data['method']),
                'order_id' => $order->id,
                'order_return_id' => $data['order_return_id'] ?? null,
                // Money taken or paid back by a cashier with an open shift goes through that drawer.
                'cash_shift_id' => Auth::id() ? CashShift::query()->where('open_user_id', Auth::id())->value('id') : null,
                'customer_id' => $order->customer_id,
                'kind' => $kind,
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_at' => $paidAt,
                'created_by' => Auth::id(),
            ])->save();

            $this->recompute($order);
            $this->audit->record("order.{$kind}", $order, ['order' => $order->external_order_id, 'amount' => $amount, 'method' => $payment->method]);

            return $payment;
        });
    }

    public function void(OrderPayment $payment, string $reason): OrderPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = OrderPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->voided_at !== null) {
                return $payment;
            }
            PeriodLock::assertOpen($payment->paid_at, 'payment', 'hủy');
            // The drawer of a closed shift has been counted: changing its money needs the shift supervisor.
            $shift = $payment->cash_shift_id ? CashShift::query()->find($payment->cash_shift_id) : null;
            if ($shift !== null && ! $shift->isOpen() && ! app(TenantContext::class)->can('orders.shifts')) {
                throw ValidationException::withMessages(['payment' => "Phiếu này thuộc ca {$shift->number} đã đóng. Cần quyền quản lý ca để hủy."]);
            }
            // The set-off of an exchange has a twin entry on the other order: voiding one side would unbalance them.
            if ($payment->method === OrderPayment::EXCHANGE) {
                throw ValidationException::withMessages(['payment' => 'Không hủy được bút toán bù trừ đổi hàng.']);
            }

            $payment->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason])->save();
            $this->recompute($order);
            $this->audit->record('order.payment_voided', $order, ['order' => $order->external_order_id, 'amount' => $payment->amount, 'reason' => $reason]);

            return $payment;
        });
    }

    /** Must run under the order row lock. */
    public function recompute(Order $order): void
    {
        $sums = OrderPayment::query()->where('order_id', $order->id)->whereNull('voided_at')
            ->selectRaw('kind, SUM(amount) as total')->groupBy('kind')->pluck('total', 'kind');

        $paid = $this->money($sums[OrderPayment::PAYMENT] ?? 0);
        $refunded = $this->money($sums[OrderPayment::REFUND] ?? 0);
        $isPaid = bccomp($paid, (string) $order->total, 2) >= 0;

        $order->forceFill([
            'paid_amount' => $paid,
            'refunded_amount' => $refunded,
            'payment_status' => $isPaid ? Order::PAYMENT_PAID : (bccomp($paid, '0', 2) > 0 ? Order::PAYMENT_PARTIAL : Order::PAYMENT_UNPAID),
            'paid_at' => $isPaid ? ($order->paid_at ?? now()) : null,
        ])->save();
    }

    private function money(string|int|float $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
