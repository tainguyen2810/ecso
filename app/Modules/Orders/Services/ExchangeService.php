<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exchanges ("đổi hàng"): the buyer returns goods and takes a new order. What the returned goods are worth is set off
 * against the new order instead of paying cash back and taking cash again:
 *   credit = min(value of the returned goods, what the buyer paid on the original order and was not refunded, new total)
 * and is booked as two linked entries with method "exchange": a refund on the original order and a payment on the new
 * one. Cash reports by method therefore stay right. Marketplace orders get the link only: the marketplace pays refunds.
 */
class ExchangeService
{
    public function __construct(private readonly PaymentService $payments, private readonly AuditLogger $audit) {}

    public function attach(OrderReturn $return, Order $exchange): OrderReturn
    {
        return DB::transaction(function () use ($return, $exchange) {
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->exchange_order_id !== null) {
                throw ValidationException::withMessages(['exchange_return_id' => 'Phiếu trả này đã dùng cho một đơn đổi khác.']);
            }
            if ($return->status === OrderReturn::REJECTED) {
                throw ValidationException::withMessages(['exchange_return_id' => 'Phiếu trả đã bị từ chối.']);
            }
            $original = Order::query()->whereKey($return->order_id)->firstOrFail();
            if ($original->id === $exchange->id) {
                throw ValidationException::withMessages(['exchange_return_id' => 'Đơn đổi phải là đơn mới.']);
            }

            $credit = '0.00';
            if ($original->isManual() && $exchange->isManual()) {
                $refundable = bcsub((string) $original->paid_amount, (string) $original->refunded_amount, 2);
                $credit = $this->min($return->goodsValue(), $refundable, $exchange->balanceDue());
            }

            if (bccomp($credit, '0', 2) > 0) {
                $this->payments->record($original, [
                    'kind' => OrderPayment::REFUND, 'method' => OrderPayment::EXCHANGE, 'amount' => $credit,
                    'reference' => $exchange->external_order_id, 'note' => "Bù trừ sang đơn đổi {$exchange->external_order_id}",
                ]);
                $this->payments->record($exchange, [
                    'method' => OrderPayment::EXCHANGE, 'amount' => $credit,
                    'reference' => $return->number, 'note' => "Bù trừ từ phiếu trả {$return->number}",
                ]);
            }

            $return->forceFill(['exchange_order_id' => $exchange->id, 'exchange_credit' => $credit])->save();
            $this->audit->record('return.exchanged', $return, ['return' => $return->number, 'order' => $exchange->external_order_id, 'credit' => $credit]);

            return $return;
        });
    }

    private function min(string ...$values): string
    {
        $min = array_shift($values);
        foreach ($values as $value) {
            if (bccomp($value, $min, 2) < 0) {
                $min = $value;
            }
        }

        return bccomp($min, '0', 2) > 0 ? bcadd($min, '0', 2) : '0.00';
    }
}
