<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Goods ordered from suppliers and not received yet ("hàng đang về"), from purchase orders that are ordered or
 * partly received.
 */
class OnOrderQuery
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<int, int>  $variantIds
     * @return array<int, array<int, int>> [variant id => [warehouse id => quantity]]
     */
    public function byVariantAndWarehouse(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_orders.tenant_id', $this->context->id())
            ->whereIn('purchase_orders.status', [PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL])
            ->whereIn('purchase_order_lines.product_variant_id', $variantIds)
            ->groupBy('purchase_order_lines.product_variant_id', 'purchase_orders.warehouse_id')
            ->selectRaw('purchase_order_lines.product_variant_id as variant_id, purchase_orders.warehouse_id, SUM(purchase_order_lines.quantity - purchase_order_lines.received_qty) as qty')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->variant_id][(int) $row->warehouse_id] = max(0, (int) $row->qty);
        }

        return $result;
    }
}
