<?php

namespace App\Modules\Sales\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\OrderPayment;

/**
 * The customer's debt book, built from the same orders the debt figure counts (cancelled and returned orders left
 * out): an order adds its total, a payment on it (not voided) takes its amount off. Newest first, with the balance
 * after each line.
 */
class CustomerLedger
{
    public const LIMIT = 300;

    /**
     * @return list<array{type: string, ref: string|null, order_id: string|null, at: string|null, note: string|null, change: string, balance: string}>
     */
    public function entries(Customer $customer): array
    {
        $orders = Order::query()->where('customer_id', $customer->id)
            ->whereNotIn('status', [OrderStatus::CANCELLED, OrderStatus::RETURNED])
            ->get(['id', 'ulid', 'external_order_id', 'total', 'placed_at', 'created_at']);
        $byId = $orders->keyBy('id');

        $lines = $orders->map(fn (Order $o) => [
            'type' => 'order', 'ref' => $o->external_order_id, 'order_id' => $o->ulid,
            'at' => ($o->placed_at ?? $o->created_at)?->toIso8601String(), 'note' => 'Ghi nhận đơn hàng', 'change' => (string) $o->total,
        ])->all();

        $payments = OrderPayment::query()->whereIn('order_id', $orders->pluck('id'))
            ->where('kind', OrderPayment::PAYMENT)->whereNull('voided_at')->get(['ulid', 'order_id', 'method', 'amount', 'paid_at']);
        foreach ($payments as $p) {
            $order = $byId[$p->order_id];
            $lines[] = [
                'type' => 'payment', 'ref' => $order->external_order_id, 'order_id' => $order->ulid,
                'at' => $p->paid_at->toIso8601String(), 'note' => 'Thu tiền', 'change' => bcsub('0', (string) $p->amount, 2),
            ];
        }

        // Oldest first to run the balance; an order comes before payments made at the same moment.
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
