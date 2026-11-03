<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders: draft → ordered → partial → received, or closed early / cancelled.
 * Goods arrive through stock receipts (RCV) linked to the order, so the weighted average cost is updated by
 * InventoryService exactly as for a manual receipt. What is owed is the value received less the value returned.
 */
class PurchaseService
{
    public function __construct(private readonly InventoryDocumentService $documents, private readonly AuditLogger $audit) {}

    /**
     * @param  array{supplier: Supplier, warehouse: Warehouse, lines: array<int, array{variant_id: int, quantity: int, unit_cost: string|int|float}>, expected_at?: string|null, note?: string|null, place?: bool}  $data
     */
    public function create(array $data): PurchaseOrder
    {
        $this->assertSupplier($data['supplier']);

        return DB::transaction(function () use ($data) {
            $po = new PurchaseOrder([
                'supplier_id' => $data['supplier']->id,
                'warehouse_id' => $data['warehouse']->id,
                'status' => PurchaseOrder::DRAFT,
                'expected_at' => $data['expected_at'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            $po->forceFill(['number' => DocumentNumber::next('PO'), 'created_by' => Auth::id()])->save();
            $this->replaceLines($po, $data['lines']);
            $this->audit->record('purchase.created', $po, ['number' => $po->number]);

            if ($data['place'] ?? false) {
                $this->markOrdered($po);
            }

            return $po;
        });
    }

    /**
     * Header and lines can change until the first goods arrive.
     *
     * @param  array{supplier?: Supplier, warehouse?: Warehouse, lines?: array<int, array{variant_id: int, quantity: int, unit_cost: string|int|float}>, expected_at?: string|null, note?: string|null}  $data
     */
    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data) {
            $po = $this->lock($po);
            if (! $this->editable($po)) {
                throw ValidationException::withMessages(['status' => 'Đơn nhập đã nhận hàng hoặc đã đóng, không sửa được nữa.']);
            }
            if (isset($data['supplier'])) {
                $this->assertSupplier($data['supplier']);
                $po->supplier_id = $data['supplier']->id;
            }
            if (isset($data['warehouse'])) {
                $po->warehouse_id = $data['warehouse']->id;
            }
            foreach (['expected_at', 'note'] as $field) {
                if (array_key_exists($field, $data)) {
                    $po->{$field} = $data[$field];
                }
            }
            $po->save();
            if (isset($data['lines'])) {
                $this->replaceLines($po, $data['lines']);
            }
            $this->audit->record('purchase.updated', $po, ['number' => $po->number]);

            return $po;
        });
    }

    public function place(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            $po = $this->lock($po);
            if ($po->status !== PurchaseOrder::DRAFT) {
                throw ValidationException::withMessages(['status' => 'Chỉ đơn nháp mới đặt hàng được.']);
            }
            $this->markOrdered($po);

            return $po;
        });
    }

    /**
     * Receives some or all of the outstanding goods. The actual unit cost may differ from the ordered one
     * (price change, discount); the receipt and the average cost use the actual cost.
     *
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost?: string|int|float|null}>  $lines
     */
    public function receive(PurchaseOrder $po, array $lines, ?string $note = null): StockReceipt
    {
        return DB::transaction(function () use ($po, $lines, $note) {
            $po = $this->lock($po);
            if (! in_array($po->status, PurchaseOrder::OPEN, true)) {
                throw ValidationException::withMessages(['status' => 'Đơn nhập đã hoàn tất, đóng hoặc hủy.']);
            }

            /** @var Collection<int, PurchaseOrderLine> $poLines */
            $poLines = $po->lines()->lockForUpdate()->get()->keyBy('product_variant_id');
            $receiptLines = [];
            $value = '0';

            foreach ($lines as $i => $line) {
                $poLine = $poLines->get($line['variant_id']);
                if (! $poLine) {
                    throw ValidationException::withMessages(["lines.{$i}.variant_id" => 'SKU không có trong đơn nhập.']);
                }
                if ($line['quantity'] < 1) {
                    continue;
                }
                if ($line['quantity'] > $poLine->remaining()) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => "Chỉ còn {$poLine->remaining()} chưa nhận."]);
                }
                $cost = bcadd((string) ($line['unit_cost'] ?? $poLine->unit_cost), '0', 4);
                $receiptLines[] = ['variant_id' => $poLine->product_variant_id, 'quantity' => $line['quantity'], 'unit_cost' => $cost];
                $value = bcadd($value, bcmul($cost, (string) $line['quantity'], 4), 4);
                $poLine->increment('received_qty', $line['quantity']);
            }

            if ($receiptLines === []) {
                throw ValidationException::withMessages(['lines' => 'Nhập số lượng nhận cho ít nhất một dòng.']);
            }

            $warehouse = Warehouse::query()->findOrFail($po->warehouse_id);
            $receipt = $this->documents->receive($warehouse, $receiptLines, $note ?? "Nhận hàng {$po->number}", StockReceipt::SOURCE_PURCHASE, $po->id);

            $complete = $poLines->every(fn (PurchaseOrderLine $l) => $l->remaining() === 0);
            $po->forceFill([
                'received_value' => bcadd((string) $po->received_value, $value, 2),
                'status' => $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIAL,
                'ordered_at' => $po->ordered_at ?? now(),
                'received_at' => now(),
            ])->save();
            $this->audit->record('purchase.received', $po, ['number' => $po->number, 'receipt' => $receipt->number, 'value' => $value]);

            return $receipt;
        });
    }

    /** Stops waiting for the rest of a partly received order. */
    public function close(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason) {
            $po = $this->lock($po);
            if ($po->status !== PurchaseOrder::PARTIAL) {
                throw ValidationException::withMessages(['status' => 'Chỉ đóng được đơn đã nhận một phần. Đơn chưa nhận hàng thì hủy.']);
            }
            $po->forceFill(['status' => PurchaseOrder::CLOSED, 'closed_at' => now()])->save();
            $this->audit->record('purchase.closed', $po, ['number' => $po->number, 'reason' => $reason]);

            return $po;
        });
    }

    public function cancel(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason) {
            $po = $this->lock($po);
            if (! in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED], true)) {
                throw ValidationException::withMessages(['status' => 'Đơn đã nhận hàng, không hủy được. Hãy đóng đơn hoặc tạo phiếu trả hàng.']);
            }
            if ($po->payments()->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['status' => 'Đơn đã có phiếu chi, hãy hủy phiếu chi trước.']);
            }
            $po->forceFill(['status' => PurchaseOrder::CANCELLED, 'closed_at' => now()])->save();
            $this->audit->record('purchase.cancelled', $po, ['number' => $po->number, 'reason' => $reason]);

            return $po;
        });
    }

    public function editable(PurchaseOrder $po): bool
    {
        return in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED], true) && (float) $po->received_value == 0.0;
    }

    private function markOrdered(PurchaseOrder $po): void
    {
        $po->forceFill(['status' => PurchaseOrder::ORDERED, 'ordered_at' => now()])->save();
        $this->audit->record('purchase.ordered', $po, ['number' => $po->number]);
    }

    /**
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost: string|int|float}>  $lines
     */
    private function replaceLines(PurchaseOrder $po, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Đơn nhập cần ít nhất một sản phẩm.']);
        }
        $po->lines()->delete();
        $total = '0';
        $merged = [];
        foreach ($lines as $line) {
            $key = $line['variant_id'];
            $cost = bcadd((string) $line['unit_cost'], '0', 4);
            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $line['quantity'];
            } else {
                $merged[$key] = ['product_variant_id' => $key, 'quantity' => $line['quantity'], 'unit_cost' => $cost];
            }
            $total = bcadd($total, bcmul($cost, (string) $line['quantity'], 4), 4);
        }
        $po->lines()->createMany(array_values($merged));
        $po->forceFill(['total' => bcadd($total, '0', 2)])->save();
    }

    private function assertSupplier(Supplier $supplier): void
    {
        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Nhà cung cấp đã ngừng giao dịch.']);
        }
    }

    private function lock(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
    }
}
