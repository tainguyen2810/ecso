<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Events\AvailableStockChanged;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OperationResult;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of stock_balances, stock_movements and stock_reservations (INVENTORY-ENGINE.md §3).
 *
 * Every operation:
 *  1. makes sure the balance rows exist (inserting only missing rows, outside the locking transaction),
 *  2. opens a transaction (retried up to 3 times on deadlock / lock wait timeout),
 *  3. locks variant rows (shared; exclusive for cost updates) and then balance rows FOR UPDATE, one by one in
 *     ascending (warehouse_id, product_variant_id) order so two operations can never wait on each other in a cycle,
 *  4. checks the idempotency key, so a retried call is a no-op,
 *  5. checks invariants, updates balances (version + 1) and appends immutable movements with after-snapshots,
 *  6. fires AvailableStockChanged after commit.
 *
 * Movement idempotency keys are "{operation key}#{n}". The operation is considered applied when "#0" exists.
 */
class InventoryService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Goods in (receipt, opening stock, return restock). Receipts update the weighted average cost.
     *
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost?: string|float|int|null}>  $lines
     */
    public function receive(Warehouse $warehouse, array $lines, string $key, ?Model $reference = null, string $type = StockMovement::RECEIPT, ?string $reason = null): OperationResult
    {
        $this->assertType($type, [StockMovement::RECEIPT, StockMovement::OPENING, StockMovement::RETURN_RESTOCK]);
        $lines = $this->normalizeLines($lines, positive: true);
        $updatesCost = $type !== StockMovement::RETURN_RESTOCK;

        return $this->run($key, $warehouse, array_column($lines, 'variant_id'), function (array $balances) use ($lines, $key, $reference, $type, $reason, $updatesCost) {
            $movements = [];

            foreach ($lines as $i => $line) {
                $balance = $balances[$line['variant_id']];
                $unitCost = $updatesCost ? $this->money($line['unit_cost'] ?? '0') : null;

                if ($updatesCost) {
                    $this->updateAverageCost($line['variant_id'], $line['quantity'], $unitCost);
                }

                $movements[] = $this->write($balance, $type, $line['quantity'], 0, "{$key}#{$i}", $reference, $unitCost, $reason);
            }

            return new OperationResult(true, $movements);
        }, lockVariants: $updatesCost);
    }

    /**
     * Goods out that do not consume a reservation (transfer out, damage, supplier return, manual shipment).
     * Never takes stock that is reserved for someone else unless the warehouse allows negative stock.
     *
     * @param  array<int, array{variant_id: int, quantity: int}>  $lines
     */
    public function issue(Warehouse $warehouse, array $lines, string $key, ?Model $reference, string $type, ?string $reason = null): OperationResult
    {
        $this->assertType($type, [StockMovement::TRANSFER_OUT, StockMovement::DAMAGE_OUT, StockMovement::SUPPLIER_RETURN, StockMovement::SHIP_UNRESERVED]);
        $lines = $this->normalizeLines($lines, positive: true);

        return $this->run($key, $warehouse, array_column($lines, 'variant_id'), function (array $balances) use ($warehouse, $lines, $key, $reference, $type, $reason) {
            $movements = [];

            foreach ($lines as $i => $line) {
                $balance = $balances[$line['variant_id']];

                if (! $warehouse->allow_negative && $balance->available() < $line['quantity']) {
                    throw $this->insufficient($balance, $line['quantity'], $balance->available());
                }

                $movements[] = $this->write($balance, $type, -$line['quantity'], 0, "{$key}#{$i}", $reference, null, $reason);
            }

            return new OperationResult(true, $movements);
        });
    }

    /**
     * Goods in from another warehouse of the same tenant. Does not touch average cost.
     *
     * @param  array<int, array{variant_id: int, quantity: int}>  $lines
     */
    public function transferIn(Warehouse $warehouse, array $lines, string $key, ?Model $reference, ?string $reason = null): OperationResult
    {
        $lines = $this->normalizeLines($lines, positive: true);

        return $this->run($key, $warehouse, array_column($lines, 'variant_id'), function (array $balances) use ($lines, $key, $reference, $reason) {
            $movements = [];
            foreach ($lines as $i => $line) {
                $movements[] = $this->write($balances[$line['variant_id']], StockMovement::TRANSFER_IN, $line['quantity'], 0, "{$key}#{$i}", $reference, null, $reason);
            }

            return new OperationResult(true, $movements);
        });
    }

    /**
     * Applies an approved adjustment. Each line is either a signed delta or a physical count (delta computed under lock).
     * on_hand may not go below zero unless the warehouse allows negative stock. It may go below reserved:
     * that is the physical truth and shows up as negative available ("thiếu hàng") for the order team.
     *
     * @param  array<int, array{variant_id: int, mode: string, quantity: int}>  $lines
     * @return array<int, array{variant_id: int, on_hand_before: int, applied_delta: int}>|null null when already applied
     */
    public function adjust(Warehouse $warehouse, array $lines, string $key, ?Model $reference, ?string $reason = null): ?array
    {
        $variantIds = array_map(fn ($l) => (int) $l['variant_id'], $lines);
        if (count($variantIds) !== count(array_unique($variantIds)) || $variantIds === []) {
            throw new InvalidStockOperation('Mỗi SKU chỉ được xuất hiện một lần trong phiếu.');
        }

        $result = $this->run($key, $warehouse, $variantIds, function (array $balances) use ($warehouse, $lines, $key, $reference, $reason) {
            $applied = [];
            $n = 0;

            foreach ($lines as $line) {
                $balance = $balances[(int) $line['variant_id']];
                $before = $balance->on_hand;
                $delta = $line['mode'] === 'count' ? (int) $line['quantity'] - $before : (int) $line['quantity'];

                if ($line['mode'] === 'count' && (int) $line['quantity'] < 0) {
                    throw new InvalidStockOperation('Số lượng kiểm kê không được âm.');
                }

                if (! $warehouse->allow_negative && $before + $delta < 0) {
                    throw $this->insufficient($balance, -$delta, $before);
                }

                if ($delta !== 0) {
                    $type = $delta > 0 ? StockMovement::ADJUSTMENT_IN : StockMovement::ADJUSTMENT_OUT;
                    $this->write($balance, $type, $delta, 0, "{$key}#{$n}", $reference, null, $reason);
                    $n++;
                }

                $applied[] = ['variant_id' => (int) $line['variant_id'], 'on_hand_before' => $before, 'applied_delta' => $delta];
            }

            return $applied;
        }, markerKey: "{$key}#done");

        return $result instanceof OperationResult ? null : $result;
    }

    /**
     * Holds stock for a reference (an order item from Phase 3). Idempotent per (reference, warehouse) and per key.
     * When stock is short the reservation holds what is available and records the rest as shortage:
     * the order already exists on the marketplace, so it must not be rejected, but nothing is held beyond available.
     */
    public function reserve(Warehouse $warehouse, ProductVariant $variant, int $quantity, string $referenceType, int $referenceId, string $key, bool $allowShortage = true): StockReservation
    {
        if ($quantity <= 0) {
            throw new InvalidStockOperation('Số lượng giữ phải lớn hơn 0.');
        }

        $find = fn (bool $lock = false) => StockReservation::query()
            ->where('reference_type', $referenceType)->where('reference_id', $referenceId)->where('warehouse_id', $warehouse->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();

        $result = $this->run($key, $warehouse, [$variant->id], function (array $balances) use ($warehouse, $variant, $quantity, $referenceType, $referenceId, $key, $allowShortage, $find) {
            $existing = $find(true);
            if ($existing && $existing->status !== StockReservation::RELEASED) {
                return $existing;
            }

            $balance = $balances[$variant->id];
            $reservable = min($quantity, max(0, $balance->available()));

            if (! $allowShortage && $reservable < $quantity) {
                throw $this->insufficient($balance, $quantity, max(0, $balance->available()));
            }

            $reservation = $existing ?? new StockReservation;
            $reservation->forceFill([
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'quantity' => $reservable,
                'shortage' => $quantity - $reservable,
                'status' => StockReservation::ACTIVE,
                'released_at' => null,
                'consumed_at' => null,
            ])->save();

            if ($reservable > 0) {
                $this->write($balance, StockMovement::RESERVE, 0, $reservable, "{$key}#0", $reservation, null, null);
            }

            return $reservation;
        });

        return $result instanceof StockReservation ? $result : $find() ?? throw new InvalidStockOperation('Không tìm thấy reservation.');
    }

    /** Gives reserved stock back (order cancelled before shipping). No-op if not active. */
    public function release(StockReservation $reservation, string $key, ?string $reason = null): StockReservation
    {
        return $this->settle($reservation, $key, StockMovement::RELEASE, $reason);
    }

    /** Ships reserved stock: on_hand and reserved both go down. The shortage part is handled by the order engine. */
    public function ship(StockReservation $reservation, string $key, ?string $reason = null): StockReservation
    {
        return $this->settle($reservation, $key, StockMovement::SHIP, $reason);
    }

    public function setSafetyStock(Warehouse $warehouse, ProductVariant $variant, int $safetyStock): StockBalance
    {
        if ($safetyStock < 0) {
            throw new InvalidStockOperation('Tồn an toàn không được âm.');
        }

        $this->ensureBalances($warehouse, [$variant->id]);

        $changed = DB::transaction(function () use ($warehouse, $variant, $safetyStock) {
            $balance = $this->lockBalance($warehouse->id, $variant->id);
            $changed = $balance->safety_stock !== $safetyStock;
            $balance->forceFill(['safety_stock' => $safetyStock, 'version' => $balance->version + 1])->save();

            return $changed;
        }, self::DEADLOCK_ATTEMPTS);

        if ($changed) {
            AvailableStockChanged::dispatch($this->context->id(), [$variant->id]);
        }

        return StockBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->firstOrFail();
    }

    // ------------------------------------------------------------------ internals

    private function settle(StockReservation $reservation, string $key, string $type, ?string $reason): StockReservation
    {
        $warehouse = Warehouse::query()->withTrashed()->findOrFail($reservation->warehouse_id);

        $this->run($key, $warehouse, [$reservation->product_variant_id], function (array $balances) use ($reservation, $warehouse, $key, $type, $reason) {
            $locked = StockReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                return OperationResult::duplicate();
            }

            $balance = $balances[$locked->product_variant_id];
            $qty = $locked->quantity;

            if ($type === StockMovement::SHIP && ! $warehouse->allow_negative && $balance->on_hand < $qty) {
                throw $this->insufficient($balance, $qty, $balance->on_hand);
            }

            if ($qty > 0) {
                $this->write($balance, $type, $type === StockMovement::SHIP ? -$qty : 0, -$qty, "{$key}#0", $locked, null, $reason);
            }

            $locked->forceFill($type === StockMovement::SHIP
                ? ['status' => StockReservation::CONSUMED, 'consumed_at' => now()]
                : ['status' => StockReservation::RELEASED, 'released_at' => now()])->save();

            return new OperationResult(true);
        });

        return $reservation->refresh();
    }

    /**
     * @param  array<int, int>  $variantIds
     * @param  Closure(array<int, StockBalance>): mixed  $work  balances keyed by variant id
     */
    private function run(string $key, Warehouse $warehouse, array $variantIds, Closure $work, bool $lockVariants = false, ?string $markerKey = null): mixed
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        sort($variantIds);
        $this->assertBelongsToTenant($warehouse, $variantIds);
        $this->ensureBalances($warehouse, $variantIds);

        $markerKey ??= "{$key}#0";
        $changed = [];

        try {
            $result = DB::transaction(function () use ($warehouse, $variantIds, $work, $lockVariants, $markerKey, &$changed) {
                // Variant rows first, always: inserting a movement or reservation takes a shared FK lock on the
                // variant, so taking it up front keeps the order variants → balances for every operation
                // (receipts need it exclusive to update the average cost). Found by tests/Concurrency.
                $variants = ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->orderBy('id');
                ($lockVariants ? $variants->lockForUpdate() : $variants->sharedLock())->get(['id']);

                $balances = [];
                foreach ($variantIds as $variantId) {
                    $balances[$variantId] = $this->lockBalance($warehouse->id, $variantId);
                }

                if ($this->applied($markerKey)) {
                    return OperationResult::duplicate();
                }

                $before = array_map(fn (StockBalance $b) => $b->version, $balances);
                $result = $work($balances);

                if (str_ends_with($markerKey, '#done')) {
                    $this->writeMarker($warehouse, $variantIds[0], $markerKey);
                }

                $changed = array_keys(array_filter($balances, fn (StockBalance $b, int $id) => $b->version !== $before[$id], ARRAY_FILTER_USE_BOTH));

                return $result;
            }, self::DEADLOCK_ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent call with the same key committed first.
            if ($this->applied($markerKey)) {
                return OperationResult::duplicate();
            }
            throw $e;
        }

        if ($changed !== []) {
            $tenantId = $this->context->id();
            DB::afterCommit(fn () => AvailableStockChanged::dispatch($tenantId, $changed));
        }

        return $result;
    }

    /**
     * Multi-line adjustments may legitimately produce zero movements (counted == on hand); a zero-delta marker
     * movement records that the operation ran, keeping retries idempotent and leaving an audit trail.
     */
    private function writeMarker(Warehouse $warehouse, int $variantId, string $key): void
    {
        $balance = StockBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variantId)->firstOrFail();
        (new StockMovement)->forceFill([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variantId,
            'type' => 'MARKER',
            'on_hand_delta' => 0,
            'reserved_delta' => 0,
            'on_hand_after' => $balance->on_hand,
            'reserved_after' => $balance->reserved,
            'idempotency_key' => $key,
            ...$this->actor(),
            'reason' => 'operation-complete',
        ])->save();
    }

    private function applied(string $markerKey): bool
    {
        return StockMovement::query()->where('idempotency_key', $markerKey)->exists();
    }

    /**
     * @param  array<int, int>  $variantIds
     */
    private function ensureBalances(Warehouse $warehouse, array $variantIds): void
    {
        // INSERT IGNORE on a row that already exists takes a shared lock on it, which deadlocks with transactions
        // holding that row FOR UPDATE (found by tests/Concurrency). So only rows that are missing are inserted.
        $existing = StockBalance::query()->where('warehouse_id', $warehouse->id)
            ->whereIn('product_variant_id', $variantIds)->pluck('product_variant_id')->all();
        $missing = array_values(array_diff($variantIds, $existing));

        if ($missing === []) {
            return;
        }

        $now = now();
        $tenantId = $this->context->id();
        $rows = array_map(fn (int $id) => [
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $missing);

        // Two first-time writers of the same new SKU can still collide; that is retried like any deadlock.
        DB::transaction(fn () => DB::table('stock_balances')->insertOrIgnore($rows), self::DEADLOCK_ATTEMPTS);
    }

    private function lockBalance(int $warehouseId, int $variantId): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function write(StockBalance $balance, string $type, int $onHandDelta, int $reservedDelta, string $key, ?Model $reference, ?string $unitCost, ?string $reason): StockMovement
    {
        $onHand = $balance->on_hand + $onHandDelta;
        $reserved = $balance->reserved + $reservedDelta;

        if ($reserved < 0) {
            throw new InvalidStockOperation('Số lượng đang giữ không thể âm.');
        }

        $balance->forceFill(['on_hand' => $onHand, 'reserved' => $reserved, 'version' => $balance->version + 1])->save();

        $movement = new StockMovement;
        $movement->forceFill([
            'warehouse_id' => $balance->warehouse_id,
            'product_variant_id' => $balance->product_variant_id,
            'type' => $type,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'on_hand_after' => $onHand,
            'reserved_after' => $reserved,
            'unit_cost' => $unitCost,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id' => $reference?->getKey(),
            'idempotency_key' => $key,
            ...$this->actor(),
            'reason' => $reason,
        ])->save();

        return $movement;
    }

    /**
     * Weighted average: (stock on hand across warehouses × current cost + received × unit cost) / new total.
     * Negative on-hand is treated as zero so a negative warehouse cannot produce a negative cost.
     */
    private function updateAverageCost(int $variantId, int $quantity, string $unitCost): void
    {
        $variant = ProductVariant::query()->withTrashed()->findOrFail($variantId);
        $onHand = max(0, (int) StockBalance::query()->where('product_variant_id', $variantId)->sum('on_hand'));
        $current = $this->money($variant->cost_price ?? '0');

        $total = bcadd(bcmul((string) $onHand, $current, 4), bcmul((string) $quantity, $unitCost, 4), 4);
        $average = bcdiv($total, (string) ($onHand + $quantity), 4);

        $variant->forceFill(['cost_price' => $average])->saveQuietly();
    }

    /**
     * @param  array<int, int>  $variantIds
     */
    private function assertBelongsToTenant(Warehouse $warehouse, array $variantIds): void
    {
        $tenantId = $this->context->id();

        if ($warehouse->tenant_id !== $tenantId) {
            throw new InvalidStockOperation('Kho không thuộc doanh nghiệp hiện tại.');
        }

        if (ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->count() !== count($variantIds)) {
            throw new InvalidStockOperation('SKU không thuộc doanh nghiệp hiện tại.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{variant_id: int, quantity: int, unit_cost?: mixed}>
     */
    private function normalizeLines(array $lines, bool $positive): array
    {
        if ($lines === []) {
            throw new InvalidStockOperation('Phiếu phải có ít nhất một dòng.');
        }

        $seen = [];
        $out = [];
        foreach (array_values($lines) as $line) {
            $id = (int) $line['variant_id'];
            $qty = (int) $line['quantity'];

            if (isset($seen[$id])) {
                throw new InvalidStockOperation('Mỗi SKU chỉ được xuất hiện một lần trong phiếu.');
            }
            if ($positive && $qty <= 0) {
                throw new InvalidStockOperation('Số lượng phải lớn hơn 0.');
            }

            $seen[$id] = true;
            $out[] = ['variant_id' => $id, 'quantity' => $qty] + (array_key_exists('unit_cost', $line) ? ['unit_cost' => $line['unit_cost']] : []);
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertType(string $type, array $allowed): void
    {
        if (! in_array($type, $allowed, true)) {
            throw new InvalidStockOperation("Loại movement {$type} không hợp lệ cho thao tác này.");
        }
    }

    private function money(string|float|int $value): string
    {
        $value = is_string($value) ? trim($value) : (string) $value;
        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidStockOperation('Giá vốn không hợp lệ.');
        }

        return bcadd($value, '0', 4);
    }

    private function insufficient(StockBalance $balance, int $requested, int $available): InsufficientStock
    {
        $sku = (string) ProductVariant::query()->withTrashed()->whereKey($balance->product_variant_id)->value('sku');

        return new InsufficientStock($sku, $requested, $available);
    }

    /**
     * @return array{actor_type: string, actor_id: int|null}
     */
    private function actor(): array
    {
        $id = Auth::id();

        return ['actor_type' => $id ? 'user' : 'system', 'actor_id' => $id];
    }
}
