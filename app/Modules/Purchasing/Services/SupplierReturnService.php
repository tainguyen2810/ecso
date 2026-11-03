<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierReturn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods sent back to a supplier. Stock leaves the warehouse (SUPPLIER_RETURN) at once and the supplier debt
 * goes down by the returned value. Against a purchase order the quantity is capped by what was received and
 * the value uses the order's cost; without an order it uses the current average cost unless one is given.
 */
class SupplierReturnService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    /**
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost?: string|int|float|null}>  $lines
     */
    public function create(Supplier $supplier, Warehouse $warehouse, array $lines, ?PurchaseOrder $po = null, ?string $reason = null): SupplierReturn
    {
        if ($po && $po->supplier_id !== $supplier->id) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Đơn nhập không thuộc nhà cung cấp này.']);
        }

        return DB::transaction(function () use ($supplier, $warehouse, $lines, $po, $reason) {
            $poLines = collect();
            if ($po) {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
                if ($po->status === PurchaseOrder::CANCELLED) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'Đơn nhập đã hủy.']);
                }
                $poLines = $po->lines()->lockForUpdate()->get()->keyBy('product_variant_id');
            }
            $costs = ProductVariant::query()->whereIn('id', array_column($lines, 'variant_id'))->pluck('cost_price', 'id');

            $rows = [];
            $total = '0';
            foreach ($lines as $i => $line) {
                $cost = $line['unit_cost'] ?? null;
                if ($po) {
                    $poLine = $poLines->get($line['variant_id']);
                    if (! $poLine) {
                        throw ValidationException::withMessages(["lines.{$i}.variant_id" => 'SKU không có trong đơn nhập.']);
                    }
                    $returnable = $poLine->received_qty - $poLine->returned_qty;
                    if ($line['quantity'] > $returnable) {
                        throw ValidationException::withMessages(["lines.{$i}.quantity" => "Chỉ trả được tối đa {$returnable}."]);
                    }
                    $cost ??= $poLine->unit_cost;
                    $poLine->increment('returned_qty', $line['quantity']);
                }
                $cost = bcadd((string) ($cost ?? $costs[$line['variant_id']] ?? '0'), '0', 4);
                $rows[] = ['product_variant_id' => $line['variant_id'], 'quantity' => $line['quantity'], 'unit_cost' => $cost];
                $total = bcadd($total, bcmul($cost, (string) $line['quantity'], 4), 4);
            }

            $return = new SupplierReturn([
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $po?->id,
                'warehouse_id' => $warehouse->id,
                'total' => bcadd($total, '0', 2),
                'reason' => $reason,
            ]);
            $return->forceFill(['number' => DocumentNumber::next('TNC'), 'created_by' => Auth::id()])->save();
            $return->lines()->createMany($rows);

            $this->inventory->returnToSupplier(
                $warehouse,
                array_map(fn ($r) => ['variant_id' => $r['product_variant_id'], 'quantity' => $r['quantity']], $rows),
                "supplier-return:{$return->id}",
                $return,
                $reason,
            );

            if ($po) {
                $po->forceFill(['returned_value' => bcadd((string) $po->returned_value, (string) $return->total, 2)])->save();
            }
            $this->audit->record('purchase.returned', $return, ['number' => $return->number, 'total' => $return->total]);

            return $return;
        });
    }
}
