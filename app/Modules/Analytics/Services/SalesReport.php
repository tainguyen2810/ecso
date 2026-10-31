<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Best and slow sellers by internal SKU. Units count only orders that left the warehouse (shipped statuses). */
class SalesReport
{
    public function __construct(private readonly StockReader $stock, private readonly TenantContext $context) {}

    /** @return array<int, array<string, mixed>> */
    public function bestSellers(CarbonImmutable $from, CarbonImmutable $to, int $limit = 20): array
    {
        $rows = $this->sold()->whereBetween('orders.placed_at', [$from, $to])
            ->groupBy('order_items.product_variant_id')
            ->selectRaw('order_items.product_variant_id as variant_id, SUM(order_items.quantity) as units, SUM(order_items.quantity * order_items.unit_price - order_items.discount) as revenue, COUNT(DISTINCT orders.id) as orders')
            ->orderByDesc('units')->limit($limit)->get();

        $variants = ProductVariant::query()->with('product:id,name')->whereIn('id', $rows->pluck('variant_id'))->get()->keyBy('id');
        $onHand = $this->stock->onHandByVariant();

        return $rows->map(fn ($r) => [
            'variant' => $this->variant($variants->get($r->variant_id)),
            'units' => (int) $r->units,
            'orders' => (int) $r->orders,
            'revenue' => (float) $r->revenue,
            'on_hand' => $onHand[$r->variant_id] ?? 0,
        ])->values()->all();
    }

    /**
     * SKUs with stock that sold little or nothing in the last $days days, largest tied-up value first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function slowMovers(int $days = 30, int $maxUnits = 0, int $limit = 50): array
    {
        $since = CarbonImmutable::now()->subDays($days);
        $sold = $this->sold()->where('orders.placed_at', '>=', $since)->groupBy('order_items.product_variant_id')
            ->selectRaw('order_items.product_variant_id as variant_id, SUM(order_items.quantity) as units')->pluck('units', 'variant_id');
        $lastSale = $this->sold()->groupBy('order_items.product_variant_id')
            ->selectRaw('order_items.product_variant_id as variant_id, MAX(orders.placed_at) as last')->pluck('last', 'variant_id');

        $onHand = array_filter($this->stock->onHandByVariant(), fn ($q) => $q > 0);
        $candidates = array_filter($onHand, fn ($q, $id) => (int) ($sold[$id] ?? 0) <= $maxUnits, ARRAY_FILTER_USE_BOTH);
        $variants = ProductVariant::query()->with('product:id,name')->whereIn('id', array_keys($candidates))->get()->keyBy('id');

        return collect($candidates)->map(fn ($qty, $id) => [
            'variant' => $this->variant($variants->get($id)),
            'on_hand' => $qty,
            'units_sold' => (int) ($sold[$id] ?? 0),
            'last_sale_at' => isset($lastSale[$id]) ? CarbonImmutable::parse($lastSale[$id])->toIso8601String() : null,
            'stock_value' => round($qty * (float) ($variants->get($id)->cost_price ?? 0), 2),
        ])->filter(fn ($r) => $r['variant'] !== null)->sortByDesc('stock_value')->take($limit)->values()->all();
    }

    private function sold()
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $this->context->id())
            ->whereNotNull('order_items.product_variant_id')
            ->whereIn('orders.status', array_diff(OrderStatus::SHIPPED, [OrderStatus::RETURNED]));
    }

    /** @return array{id: string, sku: string, name: string}|null */
    private function variant(?ProductVariant $v): ?array
    {
        return $v ? ['id' => $v->ulid, 'sku' => $v->sku, 'name' => $v->displayName()] : null;
    }
}
