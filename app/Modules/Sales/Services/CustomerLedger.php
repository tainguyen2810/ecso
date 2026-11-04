<?php

namespace App\Modules\Sales\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDebtEntry;
use App\Modules\Sales\Models\OrderPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The customer's debt book, built from the same orders and the same rule as the debt figure (CustomerDebt): an order
 * adds its total, a counted return takes its value off, a payment takes its amount off and a refund puts its amount
 * back; opening balances, adjustments and their receipts add or take off their signed amount. Each line points to its source document. Newest first, with the balance after each line; the last balance is
 * the customer's debt (negative when the shop owes the customer).
 */
class CustomerLedger
{
    public const LIMIT = 300;

    /**
     * @return list<array{type: string, ref: string|null, order_id: string|null, entry_id?: string, at: string|null, note: string|null, change: string, balance: string}>
     */
    public function entries(Customer $customer): array
    {
        $orders = CustomerDebt::constrain(Order::query()->where('orders.customer_id', $customer->id))
            ->get(['id', 'ulid', 'external_order_id', 'total', 'placed_at', 'created_at']);
        $byId = $orders->keyBy('id');
        $at = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $lines = $orders->map(fn (Order $o) => [
            'type' => 'order', 'ref' => $o->external_order_id, 'order_id' => $o->ulid,
            'at' => ($o->placed_at ?? $o->created_at)?->toIso8601String(), 'note' => 'Ghi nhận đơn hàng', 'change' => bcadd((string) $o->total, '0', 2),
        ])->all();

        $returns = DB::table('order_returns as r')->whereIn('r.order_id', $orders->pluck('id'))
            ->whereIn('r.status', CustomerDebt::COUNTED_RETURNS)
            ->selectRaw('r.order_id, r.created_at, '.CustomerDebt::returnValueSql('r').' as value')->get();
        foreach ($returns as $r) {
            if (bccomp((string) $r->value, '0', 2) === 0) {
                continue;
            }
            $order = $byId[$r->order_id];
            $lines[] = [
                'type' => 'return', 'ref' => $order->external_order_id, 'order_id' => $order->ulid,
                'at' => $at($r->created_at), 'note' => 'Trả hàng', 'change' => bcsub('0', (string) $r->value, 2),
            ];
        }

        $payments = OrderPayment::query()->whereIn('order_id', $orders->pluck('id'))->whereNull('voided_at')
            ->get(['ulid', 'order_id', 'kind', 'method', 'amount', 'paid_at']);
        foreach ($payments as $p) {
            $order = $byId[$p->order_id];
            $refund = $p->kind === OrderPayment::REFUND;
            $lines[] = [
                'type' => $refund ? 'refund' : 'payment', 'ref' => $order->external_order_id, 'order_id' => $order->ulid,
                'at' => $p->paid_at->toIso8601String(),
                'note' => $p->method === OrderPayment::EXCHANGE ? ($refund ? 'Bù trừ đổi hàng (đơn gốc)' : 'Bù trừ đổi hàng (đơn mới)') : ($refund ? 'Hoàn tiền' : 'Thu tiền'),
                'change' => $refund ? bcadd((string) $p->amount, '0', 2) : bcsub('0', (string) $p->amount, 2),
            ];
        }

        $entries = CustomerDebtEntry::query()->where('customer_id', $customer->id)->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('cash_voucher_id')->orWhereHas('voucher', fn ($v) => $v->whereNull('voided_at')))
            ->get(['ulid', 'number', 'kind', 'amount', 'entry_date', 'reason', 'created_at']);
        $labels = [CustomerDebtEntry::OPENING => 'Công nợ đầu kỳ', CustomerDebtEntry::ADJUSTMENT => 'Điều chỉnh công nợ', CustomerDebtEntry::RECEIPT => 'Thu nợ'];
        foreach ($entries as $e) {
            $lines[] = [
                'type' => $e->kind, 'ref' => $e->number, 'order_id' => null, 'entry_id' => $e->ulid,
                // The document date, at the moment it was entered (keeps same-day documents in their order).
                'at' => $e->entry_date->copy()->setTimeFrom($e->created_at ?? now())->toIso8601String(),
                'note' => $labels[$e->kind].($e->kind === CustomerDebtEntry::RECEIPT ? '' : ': '.$e->reason), 'change' => bcadd((string) $e->amount, '0', 2),
            ];
        }

        // Oldest first to run the balance; an order comes before what happens to it at the same moment.
        usort($lines, fn ($a, $b) => [$a['at'], $a['type'] === 'order' ? 0 : 1] <=> [$b['at'], $b['type'] === 'order' ? 0 : 1]);
        $balance = '0';
        foreach ($lines as &$line) {
            $balance = bcadd($balance, $line['change'], 2);
            $line['balance'] = $balance;
        }
        unset($line);

        return array_slice(array_reverse($lines), 0, self::LIMIT);
    }
}
