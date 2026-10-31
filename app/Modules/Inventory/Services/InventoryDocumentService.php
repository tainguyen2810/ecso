<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\DocumentNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Receipts, adjustments and transfers. Documents own the workflow; InventoryService owns the stock.
 * Each document row is locked FOR UPDATE before balances (fixed lock order: document → variants → balances).
 */
class InventoryDocumentService
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    /**
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost: string|float|int}>  $lines
     */
    public function receive(Warehouse $warehouse, array $lines, ?string $note = null, string $source = StockReceipt::SOURCE_MANUAL): StockReceipt
    {
        $this->assertActive($warehouse);

        return DB::transaction(function () use ($warehouse, $lines, $note, $source) {
            $receipt = new StockReceipt(['warehouse_id' => $warehouse->id, 'source' => $source, 'note' => $note]);
            $receipt->forceFill(['number' => DocumentNumber::next('RCV'), 'created_by' => Auth::id()])->save();
            $receipt->lines()->createMany(array_map(fn ($l) => ['product_variant_id' => $l['variant_id'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost']], $lines));

            $type = $source === StockReceipt::SOURCE_OPENING ? StockMovement::OPENING : StockMovement::RECEIPT;
            $this->inventory->receive($warehouse, $lines, "receipt:{$receipt->id}", $receipt, $type);
            $this->audit->record('inventory.received', $receipt, ['number' => $receipt->number, 'lines' => count($lines)]);

            return $receipt;
        });
    }

    /**
     * @param  array<int, array{variant_id: int, mode: string, quantity: int}>  $lines
     */
    public function createAdjustment(Warehouse $warehouse, array $lines, string $reason, ?string $note = null): InventoryAdjustment
    {
        $this->assertActive($warehouse);

        return DB::transaction(function () use ($warehouse, $lines, $reason, $note) {
            $adjustment = new InventoryAdjustment(['warehouse_id' => $warehouse->id, 'reason' => $reason, 'note' => $note]);
            $adjustment->forceFill([
                'number' => DocumentNumber::next('ADJ'),
                'status' => InventoryAdjustment::PENDING,
                'created_by' => Auth::id(),
            ])->save();
            $adjustment->lines()->createMany(array_map(fn ($l) => ['product_variant_id' => $l['variant_id'], 'mode' => $l['mode'], 'quantity' => $l['quantity']], $lines));
            $this->audit->record('inventory.adjustment.created', $adjustment, ['number' => $adjustment->number, 'reason' => $reason]);

            return $adjustment;
        });
    }

    public function approveAdjustment(InventoryAdjustment $adjustment, ?string $note = null): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $note) {
            $locked = $this->lockPending($adjustment);
            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
            $this->assertActive($warehouse);
            $lines = $locked->lines()->get();

            $applied = $this->inventory->adjust(
                $warehouse,
                $lines->map(fn ($l) => ['variant_id' => $l->product_variant_id, 'mode' => $l->mode, 'quantity' => $l->quantity])->all(),
                "adjustment:{$locked->id}",
                $locked,
                $locked->reason,
            ) ?? throw new InvalidStockOperation('Phiếu điều chỉnh đã được áp dụng.');

            $byVariant = collect($applied)->keyBy('variant_id');
            foreach ($lines as $line) {
                $line->forceFill([
                    'on_hand_before' => $byVariant[$line->product_variant_id]['on_hand_before'],
                    'applied_delta' => $byVariant[$line->product_variant_id]['applied_delta'],
                ])->save();
            }

            $locked->forceFill(['status' => InventoryAdjustment::APPROVED, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit->record('inventory.adjustment.approved', $locked, [
                'number' => $locked->number,
                'self_approved' => $locked->created_by === Auth::id(),
                'deltas' => $byVariant->pluck('applied_delta', 'variant_id')->all(),
            ]);

            return $locked;
        });
    }

    public function rejectAdjustment(InventoryAdjustment $adjustment, ?string $note = null): InventoryAdjustment
    {
        return $this->closeAdjustment($adjustment, InventoryAdjustment::REJECTED, $note, 'inventory.adjustment.rejected');
    }

    public function cancelAdjustment(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        return $this->closeAdjustment($adjustment, InventoryAdjustment::CANCELLED, null, 'inventory.adjustment.cancelled');
    }

    /**
     * @param  array<int, array{variant_id: int, quantity: int}>  $lines
     */
    public function createTransfer(Warehouse $from, Warehouse $to, array $lines, ?string $note = null): StockTransfer
    {
        if ($from->id === $to->id) {
            throw new InvalidStockOperation('Kho nguồn và kho đích phải khác nhau.');
        }
        $this->assertActive($from);
        $this->assertActive($to);

        return DB::transaction(function () use ($from, $to, $lines, $note) {
            $transfer = new StockTransfer(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'note' => $note]);
            $transfer->forceFill(['number' => DocumentNumber::next('TRF'), 'status' => StockTransfer::DRAFT, 'created_by' => Auth::id()])->save();
            $transfer->lines()->createMany(array_map(fn ($l) => ['product_variant_id' => $l['variant_id'], 'quantity' => $l['quantity']], $lines));
            $this->audit->record('inventory.transfer.created', $transfer, ['number' => $transfer->number]);

            return $transfer;
        });
    }

    public function dispatchTransfer(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $locked = $this->lockTransfer($transfer, StockTransfer::DRAFT);
            $from = Warehouse::query()->findOrFail($locked->from_warehouse_id);
            $this->assertActive($from);

            $this->inventory->issue($from, $this->transferLines($locked), "transfer:{$locked->id}:out", $locked, StockMovement::TRANSFER_OUT);
            $locked->forceFill(['status' => StockTransfer::IN_TRANSIT, 'dispatched_at' => now()])->save();
            $this->audit->record('inventory.transfer.dispatched', $locked, ['number' => $locked->number]);

            return $locked;
        });
    }

    public function receiveTransfer(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $locked = $this->lockTransfer($transfer, StockTransfer::IN_TRANSIT);
            $to = Warehouse::query()->findOrFail($locked->to_warehouse_id);
            $this->assertActive($to);

            $this->inventory->transferIn($to, $this->transferLines($locked), "transfer:{$locked->id}:in", $locked);
            $locked->forceFill(['status' => StockTransfer::RECEIVED, 'received_at' => now()])->save();
            $this->audit->record('inventory.transfer.received', $locked, ['number' => $locked->number]);

            return $locked;
        });
    }

    /**
     * Draft: just cancelled. In transit: goods go back into the source warehouse.
     */
    public function cancelTransfer(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === StockTransfer::IN_TRANSIT) {
                $from = Warehouse::query()->withTrashed()->findOrFail($locked->from_warehouse_id);
                $this->inventory->transferIn($from, $this->transferLines($locked), "transfer:{$locked->id}:return", $locked, 'Hủy chuyển kho, trả về kho nguồn');
            } elseif ($locked->status !== StockTransfer::DRAFT) {
                throw new InvalidStockOperation('Chỉ hủy được phiếu nháp hoặc đang chuyển.');
            }

            $locked->forceFill(['status' => StockTransfer::CANCELLED])->save();
            $this->audit->record('inventory.transfer.cancelled', $locked, ['number' => $locked->number]);

            return $locked;
        });
    }

    private function closeAdjustment(InventoryAdjustment $adjustment, string $status, ?string $note, string $action): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $status, $note, $action) {
            $locked = $this->lockPending($adjustment);
            $locked->forceFill(['status' => $status, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit->record($action, $locked, ['number' => $locked->number]);

            return $locked;
        });
    }

    private function lockPending(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        $locked = InventoryAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isPending()) {
            throw new InvalidStockOperation('Phiếu điều chỉnh không còn ở trạng thái chờ duyệt.');
        }

        return $locked;
    }

    private function lockTransfer(StockTransfer $transfer, string $expected): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $expected) {
            throw new InvalidStockOperation('Trạng thái phiếu chuyển kho không hợp lệ cho thao tác này.');
        }

        return $locked;
    }

    /**
     * @return array<int, array{variant_id: int, quantity: int}>
     */
    private function transferLines(StockTransfer $transfer): array
    {
        return $transfer->lines()->get()->map(fn ($l) => ['variant_id' => $l->product_variant_id, 'quantity' => $l->quantity])->all();
    }

    private function assertActive(Warehouse $warehouse): void
    {
        if (! $warehouse->isActive()) {
            throw new InvalidStockOperation("Kho {$warehouse->code} đang ngừng hoạt động.");
        }
    }
}
