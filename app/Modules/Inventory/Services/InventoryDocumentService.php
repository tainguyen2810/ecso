<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\CostAdjustment;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBalance;
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
    public function receive(Warehouse $warehouse, array $lines, ?string $note = null, string $source = StockReceipt::SOURCE_MANUAL, ?int $purchaseOrderId = null): StockReceipt
    {
        $this->assertActive($warehouse);

        return DB::transaction(function () use ($warehouse, $lines, $note, $source, $purchaseOrderId) {
            $receipt = new StockReceipt(['warehouse_id' => $warehouse->id, 'source' => $source, 'note' => $note]);
            $receipt->forceFill(['number' => DocumentNumber::next('RCV'), 'created_by' => Auth::id(), 'purchase_order_id' => $purchaseOrderId])->save();
            $receipt->lines()->createMany(array_map(fn ($l) => ['product_variant_id' => $l['variant_id'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost']], $lines));

            $type = $source === StockReceipt::SOURCE_OPENING ? StockMovement::OPENING : StockMovement::RECEIPT;
            $this->inventory->receive($warehouse, $lines, "receipt:{$receipt->id}", $receipt, $type);
            $this->audit->record('inventory.received', $receipt, ['number' => $receipt->number, 'lines' => count($lines)]);

            return $receipt;
        });
    }

    /**
     * A draft (stocktake still being counted) is saved without asking for approval and can be edited until it is
     * submitted.
     *
     * @param  array<int, array{variant_id: int, mode: string, quantity: int, reason?: string|null, note?: string|null}>  $lines
     */
    public function createAdjustment(Warehouse $warehouse, array $lines, string $reason, ?string $note = null, bool $draft = false): InventoryAdjustment
    {
        $this->assertActive($warehouse);

        return DB::transaction(function () use ($warehouse, $lines, $reason, $note, $draft) {
            $adjustment = new InventoryAdjustment(['warehouse_id' => $warehouse->id, 'reason' => $reason, 'note' => $note]);
            $adjustment->forceFill([
                'number' => DocumentNumber::next('ADJ'),
                'status' => $draft ? InventoryAdjustment::DRAFT : InventoryAdjustment::PENDING,
                'created_by' => Auth::id(),
                'counted_at' => now(),
            ])->save();
            $adjustment->lines()->createMany($this->adjustmentRows($warehouse, $lines));
            $this->audit->record($draft ? 'inventory.adjustment.drafted' : 'inventory.adjustment.created', $adjustment, ['number' => $adjustment->number, 'reason' => $reason]);

            return $adjustment;
        });
    }

    /**
     * Saves the counts of a draft stocktake again (the whole list replaces the previous one).
     *
     * @param  array<int, array{variant_id: int, mode: string, quantity: int, reason?: string|null, note?: string|null}>  $lines
     */
    public function updateDraft(InventoryAdjustment $adjustment, array $lines, ?string $note = null): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $lines, $note) {
            $locked = $this->lockDraft($adjustment);
            $locked->forceFill(['note' => $note, 'counted_at' => now()])->save();
            $locked->lines()->delete();
            $locked->lines()->createMany($this->adjustmentRows(Warehouse::query()->withTrashed()->findOrFail($locked->warehouse_id), $lines));
            $this->audit->record('inventory.adjustment.draft_saved', $locked, ['number' => $locked->number, 'lines' => count($lines)]);

            return $locked;
        });
    }

    /** Counting is finished: the draft waits for approval like any adjustment. */
    public function submitDraft(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment) {
            $locked = $this->lockDraft($adjustment);
            if (! $locked->lines()->exists()) {
                throw new InvalidStockOperation('Phiếu kiểm chưa có sản phẩm nào được kiểm.');
            }
            $locked->forceFill(['status' => InventoryAdjustment::PENDING])->save();
            $this->audit->record('inventory.adjustment.created', $locked, ['number' => $locked->number, 'reason' => $locked->reason]);

            return $locked;
        });
    }

    /**
     * A count line keeps the system quantity it was compared with: the one the counter saw (system_qty from the
     * stocktake screen) or, when not given, the quantity on hand now. Approval applies count − system_qty.
     *
     * @param  array<int, array{variant_id: int, mode: string, quantity: int, system_qty?: int|null, reason?: string|null, note?: string|null}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function adjustmentRows(Warehouse $warehouse, array $lines): array
    {
        $missing = array_column(array_filter($lines, fn ($l) => $l['mode'] === 'count' && ! isset($l['system_qty'])), 'variant_id');
        $onHand = $missing === [] ? collect() : StockBalance::query()->where('warehouse_id', $warehouse->id)
            ->whereIn('product_variant_id', $missing)->pluck('on_hand', 'product_variant_id');

        return array_map(fn ($l) => [
            'product_variant_id' => $l['variant_id'],
            'mode' => $l['mode'],
            'quantity' => $l['quantity'],
            'system_qty' => $l['mode'] === 'count' ? (int) ($l['system_qty'] ?? $onHand[$l['variant_id']] ?? 0) : null,
            'reason' => $l['reason'] ?? null,
            'note' => $l['note'] ?? null,
        ], $lines);
    }

    private function lockDraft(InventoryAdjustment $adjustment): InventoryAdjustment
    {
        $locked = InventoryAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isDraft()) {
            throw new InvalidStockOperation('Phiếu kiểm đã gửi duyệt hoặc đã cân bằng, không sửa được nữa.');
        }

        return $locked;
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
                $lines->map(fn ($l) => ['variant_id' => $l->product_variant_id, 'mode' => $l->mode, 'quantity' => $l->quantity, 'system_qty' => $l->system_qty])->all(),
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
        return $this->closeAdjustment($adjustment, InventoryAdjustment::CANCELLED, null, 'inventory.adjustment.cancelled', allowDraft: true);
    }

    /**
     * Cost adjustment (CAD): sets the average cost of each SKU now and keeps the old cost on the line.
     * Stock quantities do not change; the next receipt averages from the new cost.
     *
     * @param  array<int, array{variant_id: int, new_cost: string|float|int}>  $lines
     */
    public function createCostAdjustment(array $lines, ?string $note = null): CostAdjustment
    {
        if ($lines === []) {
            throw new InvalidStockOperation('Phiếu phải có ít nhất một dòng.');
        }
        $costs = [];
        foreach ($lines as $line) {
            if (isset($costs[$line['variant_id']])) {
                throw new InvalidStockOperation('Mỗi SKU chỉ được xuất hiện một lần trong phiếu.');
            }
            $costs[$line['variant_id']] = $line['new_cost'];
        }

        return DB::transaction(function () use ($costs, $note) {
            $adjustment = new CostAdjustment(['note' => $note]);
            $adjustment->forceFill(['number' => DocumentNumber::next('CAD'), 'created_by' => Auth::id()])->save();

            $changes = $this->inventory->setCosts($costs);
            foreach (array_keys($costs) as $variantId) {
                $c = $changes[$variantId];
                $adjustment->lines()->create(['product_variant_id' => $variantId, 'old_cost' => $c['old'], 'new_cost' => $c['new'], 'on_hand' => $c['on_hand']]);
            }

            $this->audit->record('inventory.cost_adjusted', $adjustment, ['number' => $adjustment->number, 'lines' => count($costs)]);

            return $adjustment;
        });
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

    private function closeAdjustment(InventoryAdjustment $adjustment, string $status, ?string $note, string $action, bool $allowDraft = false): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $status, $note, $action, $allowDraft) {
            $locked = $allowDraft && $adjustment->isDraft() ? $this->lockDraft($adjustment) : $this->lockPending($adjustment);
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
