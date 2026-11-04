<?php

namespace App\Modules\Sales\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\OrderPayment;
use Illuminate\Support\Facades\DB;

/**
 * Read-only checks of the money of the seller's own orders of one tenant (run inside its TenantContext). Each problem
 * is a row: tenant, order, problem, expected, actual.
 *
 *  - paid / refunded on the order differ from its payment entries (what PaymentService::recompute keeps in sync);
 *  - payment status does not match paid against total;
 *  - a cancelled order still holds money that was never refunded;
 *  - more was refunded than was paid;
 *  - a return's refunds exceed what the return is worth.
 */
class MoneyVerifier
{
    /** @return array<int, array{tenant: int, order: string, problem: string, expected: string, actual: string}> */
    public function verify(int $tenantId): array
    {
        $problems = [];
        $sums = DB::table('order_payments')->whereNull('voided_at')
            ->where('tenant_id', $tenantId)
            ->selectRaw('order_id, kind, SUM(amount) as total')->groupBy('order_id', 'kind')->get()
            ->groupBy('order_id');

        DB::table('orders')->where('marketplace', Order::SOURCE_MANUAL)
            ->where('tenant_id', $tenantId)
            ->select(['id', 'tenant_id', 'external_order_id', 'status', 'total', 'paid_amount', 'refunded_amount', 'payment_status'])
            ->orderBy('id')->chunkById(1000, function ($orders) use ($sums, &$problems) {
                foreach ($orders as $o) {
                    $byKind = ($sums[$o->id] ?? collect())->pluck('total', 'kind');
                    $paid = bcadd((string) ($byKind[OrderPayment::PAYMENT] ?? 0), '0', 2);
                    $refunded = bcadd((string) ($byKind[OrderPayment::REFUND] ?? 0), '0', 2);
                    $add = function (string $problem, string $expected, string $actual) use ($o, &$problems) {
                        $problems[] = ['tenant' => (int) $o->tenant_id, 'order' => (string) $o->external_order_id, 'problem' => $problem, 'expected' => $expected, 'actual' => $actual];
                    };

                    if (bccomp($paid, (string) $o->paid_amount, 2) !== 0) {
                        $add('paid_amount khác tổng phiếu thu', $paid, (string) $o->paid_amount);
                    }
                    if (bccomp($refunded, (string) $o->refunded_amount, 2) !== 0) {
                        $add('refunded_amount khác tổng phiếu hoàn', $refunded, (string) $o->refunded_amount);
                    }
                    $status = bccomp((string) $o->paid_amount, (string) $o->total, 2) >= 0 ? Order::PAYMENT_PAID
                        : (bccomp((string) $o->paid_amount, '0', 2) > 0 ? Order::PAYMENT_PARTIAL : Order::PAYMENT_UNPAID);
                    if ($o->payment_status !== $status) {
                        $add('payment_status sai', $status, (string) $o->payment_status);
                    }
                    if (bccomp($refunded, $paid, 2) > 0) {
                        $add('hoàn nhiều hơn đã thu', $paid, $refunded);
                    }
                    if ($o->status === OrderStatus::CANCELLED && bccomp(bcsub($paid, $refunded, 2), '0', 2) > 0) {
                        $add('đơn hủy còn tiền chưa hoàn', '0.00', bcsub($paid, $refunded, 2));
                    }
                }
            });

        // Returns paid back beyond their value (checked with the model, which knows how a return is valued).
        $returns = DB::table('order_payments')->whereNull('voided_at')->whereNotNull('order_return_id')->where('kind', OrderPayment::REFUND)
            ->where('tenant_id', $tenantId)
            ->selectRaw('order_return_id, SUM(amount) as total')->groupBy('order_return_id')->pluck('total', 'order_return_id');
        foreach ($returns as $returnId => $total) {
            $return = OrderReturn::query()->with('order')->find($returnId);
            if ($return === null) {
                continue;
            }
            $limit = bcsub($return->goodsValue(), (string) $return->exchange_credit, 2);
            if (bccomp((string) $total, $limit, 2) > 0) {
                $problems[] = ['tenant' => (int) $return->tenant_id, 'order' => (string) ($return->order?->external_order_id ?? ''), 'problem' => "phiếu trả {$return->number} hoàn vượt giá trị", 'expected' => $limit, 'actual' => bcadd((string) $total, '0', 2)];
            }
        }

        return $problems;
    }
}
