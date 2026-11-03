<?php

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/**
 * SKUs at or below their safety stock in a warehouse (only where a safety stock is set, so new or
 * discontinued SKUs with no stock do not raise alerts), most urgent first.
 */
class LowStockQuery
{
    /**
     * @return array<int, array{variant_id: string, sku: string, name: string, warehouse: string, available: int, safety_stock: int}>
     */
    public function forTenant(int $tenantId, int $limit = 50): array
    {
        return DB::table('stock_balances as b')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.tenant_id', $tenantId)
            ->where('b.safety_stock', '>', 0)
            ->whereRaw('b.on_hand - b.reserved <= b.safety_stock')
            ->whereNull('v.deleted_at')->whereNull('w.deleted_at')
            ->where('p.status', '!=', 'archived')
            ->orderByRaw('(b.on_hand - b.reserved) - CAST(b.safety_stock AS SIGNED)')
            ->orderBy('v.sku')
            ->limit($limit)
            ->get(['v.ulid', 'v.sku', 'v.name as variant_name', 'p.name as product_name', 'w.name as warehouse', 'b.on_hand', 'b.reserved', 'b.safety_stock'])
            ->map(fn ($r) => [
                'variant_id' => $r->ulid,
                'sku' => $r->sku,
                'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : '')),
                'warehouse' => $r->warehouse,
                'available' => (int) $r->on_hand - (int) $r->reserved,
                'safety_stock' => (int) $r->safety_stock,
            ])->all();
    }
}
