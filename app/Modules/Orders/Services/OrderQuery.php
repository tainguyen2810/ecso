<?php

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/** Filters of the order list (ORDER-LIFECYCLE §7). Every filter value is resolved inside the tenant scope. */
class OrderQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    public function build(array $filters, ?int $userId, bool $withTab = true): Builder
    {
        $query = Order::query();

        if ($withTab && ($tab = $filters['tab'] ?? null) && isset(OrderStatus::TABS[$tab])) {
            $query->whereIn('status', OrderStatus::TABS[$tab]);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($account = $filters['account'] ?? null) {
            $query->where('marketplace_account_id', MarketplaceAccount::query()->where('ulid', $account)->value('id') ?? 0);
        }
        if ($channel = $filters['channel'] ?? null) {
            $query->where('sales_channel_id', SalesChannel::query()->where('ulid', $channel)->value('id') ?? 0);
        }
        if ($customer = $filters['customer'] ?? null) {
            $query->where('customer_id', Customer::query()->where('ulid', $customer)->value('id') ?? 0);
        }
        if ($source = $filters['source'] ?? null) {
            $source === 'manual' ? $query->where('marketplace', Order::SOURCE_MANUAL) : $query->where('marketplace', '!=', Order::SOURCE_MANUAL);
        }
        if ($paymentStatus = $filters['payment_status'] ?? null) {
            $query->where('payment_status', $paymentStatus);
        }
        if ($marketplace = $filters['marketplace'] ?? null) {
            $query->where('marketplace', $marketplace);
        }
        if ($from = $filters['from'] ?? null) {
            $query->where('placed_at', '>=', $from);
        }
        if ($to = $filters['to'] ?? null) {
            $query->where('placed_at', '<', Carbon::parse($to)->addDay());
        }
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn (Builder $w) => $w->where('external_order_id', 'like', "%{$q}%")
                ->orWhere('tracking_number', $q)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('phone_hash', PhoneNumber::hash(PhoneNumber::normalize($q)) ?? '-'))
                ->orWhereHas('items', fn ($i) => $i->where('seller_sku', $q)->orWhere('name', 'like', "%{$q}%")));
        }
        if ($sku = $filters['sku'] ?? null) {
            $variantId = ProductVariant::query()->where('ulid', $sku)->value('id') ?? 0;
            $query->whereHas('items', fn ($i) => $i->where('product_variant_id', $variantId));
        }
        if ($tag = $filters['tag'] ?? null) {
            $query->whereJsonContains('tags', $tag);
        }
        if ($from = $filters['deliver_from'] ?? null) {
            $query->whereDate('deliver_on', '>=', $from);
        }
        if ($to = $filters['deliver_to'] ?? null) {
            $query->whereDate('deliver_on', '<=', $to);
        }
        if ($assignee = $filters['assignee'] ?? null) {
            match ($assignee) {
                'me' => $query->where('assigned_to', $userId),
                'none' => $query->whereNull('assigned_to'),
                default => $query->where('assigned_to', User::query()->where('ulid', $assignee)->value('id') ?? 0),
            };
        }
        foreach ((array) ($filters['flags'] ?? []) as $flag) {
            match ($flag) {
                'shortage' => $query->where('has_shortage', true),
                'unmapped' => $query->where('has_unmapped', true),
                'overdue' => $query->whereIn('status', OrderStatus::OPEN)->where('ship_by_at', '<', now()),
                'due_soon' => $query->whereIn('status', OrderStatus::OPEN)->whereBetween('ship_by_at', [now(), now()->addHours(config('ecso.orders.due_soon_hours'))]),
                'warning' => $query->whereNotNull('sync_warning'),
                'not_printed' => $query->whereNull('packing_slip_printed_at'),
                'payment_due' => $query->where('marketplace', Order::SOURCE_MANUAL)->where('status', '!=', OrderStatus::CANCELLED)
                    ->where('payment_status', '!=', Order::PAYMENT_PAID),
                default => null,
            };
        }

        return $query;
    }

    /**
     * Order count per tab for the current filters (without the tab filter itself).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function tabCounts(array $filters, ?int $userId): array
    {
        $byStatus = $this->build($filters, $userId, withTab: false)->toBase()
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $counts = ['all' => (int) $byStatus->sum()];
        foreach (OrderStatus::TABS as $tab => $statuses) {
            $counts[$tab] = (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0);
        }

        return $counts;
    }

    /**
     * "Orders to handle" strip above the list: count and amount of each queue that waits for someone.
     * Payment due only counts manual orders (marketplaces collect the money themselves).
     *
     * @return array<string, array{count: int, amount: string}>
     */
    public function pipeline(): array
    {
        $byStatus = Order::query()->toBase()
            ->whereIn('status', [...OrderStatus::OPEN, OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT, OrderStatus::FAILED_DELIVERY])
            ->selectRaw('status, count(*) as c, COALESCE(SUM(total), 0) as amount')->groupBy('status')->get()->keyBy('status');
        $sum = fn (array $statuses) => [
            'count' => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s]->c ?? 0),
            'amount' => collect($statuses)->reduce(fn (string $t, $s) => bcadd($t, (string) ($byStatus[$s]->amount ?? 0), 2), '0.00'),
        ];

        $due = Order::query()->toBase()->where('marketplace', Order::SOURCE_MANUAL)->where('status', '!=', OrderStatus::CANCELLED)
            ->where('payment_status', '!=', Order::PAYMENT_PAID)
            ->selectRaw('count(*) as c, COALESCE(SUM(GREATEST(total - paid_amount, 0)), 0) as amount')->first();

        return [
            'to_confirm' => $sum([OrderStatus::PENDING_CONFIRMATION]),
            'payment_due' => ['count' => (int) ($due->c ?? 0), 'amount' => bcadd((string) ($due->amount ?? 0), '0', 2)],
            'to_pack' => $sum([OrderStatus::READY_TO_PACK]),
            'to_handover' => $sum([OrderStatus::PACKED]),
            'shipping' => $sum([OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT]),
            'failed_delivery' => $sum([OrderStatus::FAILED_DELIVERY]),
        ];
    }
}
