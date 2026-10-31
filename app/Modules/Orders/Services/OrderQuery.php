<?php

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
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
                ->orWhereHas('items', fn ($i) => $i->where('seller_sku', $q)->orWhere('name', 'like', "%{$q}%")));
        }
        if ($sku = $filters['sku'] ?? null) {
            $variantId = ProductVariant::query()->where('ulid', $sku)->value('id') ?? 0;
            $query->whereHas('items', fn ($i) => $i->where('product_variant_id', $variantId));
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
}
