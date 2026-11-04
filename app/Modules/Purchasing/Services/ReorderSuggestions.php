<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * What to buy: sales velocity over the last N days against available stock and goods already on order.
 *
 * suggested = ceil(daily sales × cover days) + safety stock − available − on order   (only when > 0)
 */
class ReorderSuggestions
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<int, array{variant_id: int, variant_ulid: string, sku: string, name: string, variant_name: string|null, sold: int, daily: float, available: int, safety_stock: int, on_order: int, suggested: int, unit_cost: string, supplier_id: int|null}>
     */
    public function compute(int $warehouseId, int $coverDays = 14, int $lookbackDays = 30, int $limit = 200): array
    {
        $tenant = $this->context->id();
        $since = now()->subDays($lookbackDays);

        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenant)
            ->where('orders.warehouse_id', $warehouseId)
            ->whereNotIn('orders.status', [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::UNPAID])
            ->where('orders.placed_at', '>=', $since)
            ->whereNotNull('order_items.product_variant_id')
            ->groupBy('order_items.product_variant_id')
            ->selectRaw('order_items.product_variant_id as variant_id, SUM(order_items.quantity) as qty')
            ->pluck('qty', 'variant_id');

        $onOrder = DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_orders.tenant_id', $tenant)
            ->where('purchase_orders.warehouse_id', $warehouseId)
            ->whereIn('purchase_orders.status', [PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL])
            ->groupBy('purchase_order_lines.product_variant_id')
            ->selectRaw('purchase_order_lines.product_variant_id as variant_id, SUM(purchase_order_lines.quantity - purchase_order_lines.received_qty) as qty')
            ->pluck('qty', 'variant_id');

        $balances = DB::table('stock_balances')
            ->where('tenant_id', $tenant)->where('warehouse_id', $warehouseId)
            ->get(['product_variant_id', 'on_hand', 'reserved', 'safety_stock', 'max_stock'])
            ->keyBy('product_variant_id');

        $candidates = collect($sold->keys())->merge($balances->filter(fn ($b) => $b->safety_stock > 0)->keys())->unique()->values();
        if ($candidates->isEmpty()) {
            return [];
        }

        // Last supplier and cost from the most recent purchase line of each variant.
        $lastPurchase = DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_orders.tenant_id', $tenant)
            ->where('purchase_orders.status', '!=', PurchaseOrder::CANCELLED)
            ->whereIn('purchase_order_lines.product_variant_id', $candidates)
            ->orderBy('purchase_order_lines.id')
            ->get(['purchase_order_lines.product_variant_id', 'purchase_order_lines.unit_cost', 'purchase_orders.supplier_id'])
            ->keyBy('product_variant_id');

        $variants = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_variants.tenant_id', $tenant)
            ->whereIn('product_variants.id', $candidates)
            ->whereNull('product_variants.deleted_at')
            ->where('products.status', '!=', 'archived')
            ->get(['product_variants.id', 'product_variants.ulid', 'product_variants.sku', 'product_variants.name as variant_name', 'products.name', 'product_variants.cost_price', 'product_variants.supplier_id']);

        $rows = [];
        foreach ($variants as $v) {
            $b = $balances->get($v->id);
            $soldQty = (int) ($sold[$v->id] ?? 0);
            $daily = $soldQty / max(1, $lookbackDays);
            $available = $b ? max(0, (int) $b->on_hand - (int) $b->reserved) : 0;
            $safety = $b ? (int) $b->safety_stock : 0;
            $pending = (int) ($onOrder[$v->id] ?? 0);
            $suggested = (int) ceil($daily * $coverDays) + $safety - $available - $pending;
            // Never suggest more than the warehouse should hold.
            if ($b && $b->max_stock !== null) {
                $suggested = min($suggested, (int) $b->max_stock - $available - $pending);
            }
            if ($suggested <= 0) {
                continue;
            }
            $last = $lastPurchase->get($v->id);
            $rows[] = [
                'variant_id' => (int) $v->id,
                'variant_ulid' => $v->ulid,
                'sku' => $v->sku,
                'name' => $v->name,
                'variant_name' => $v->variant_name,
                'sold' => $soldQty,
                'daily' => round($daily, 2),
                'available' => $available,
                'safety_stock' => $safety,
                'on_order' => $pending,
                'suggested' => $suggested,
                'unit_cost' => bcadd((string) ($last->unit_cost ?? $v->cost_price ?? '0'), '0', 2),
                // The SKU's usual supplier first, else whoever supplied it last.
                'supplier_id' => $v->supplier_id ? (int) $v->supplier_id : ($last ? (int) $last->supplier_id : null),
            ];
        }

        usort($rows, fn ($a, $b) => $b['suggested'] <=> $a['suggested']);

        return array_slice($rows, 0, $limit);
    }
}
