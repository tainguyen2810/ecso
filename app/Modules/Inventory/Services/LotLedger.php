<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotMovement;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Shared\Support\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The lot sub-ledger (lô / hạn sử dụng). Used only by InventoryService, inside its transaction and after it has
 * locked the (warehouse, SKU) balance row, so the lots of that pair cannot change underneath.
 *
 * For a SKU whose product has track_lots on, the lots of a warehouse always add up to its balance:
 *  - goods in go to the lots given, the rest to "Chưa rõ lô" (lot_no '');
 *  - a hold takes lots FEFO: "Chưa rõ lô" first (oldest stock), then the earliest expiry, never a lot that is
 *    expired or under the product's stop-selling threshold;
 *  - shipping or releasing a hold uses the lots that hold took (rows with the reservation id);
 *  - other goods out take the lots given, else FEFO over every lot.
 * A SKU without track_lots is never touched.
 */
class LotLedger
{
    public const DEFAULT_WARN_DAYS = 60;

    /** @var array<int, array{track: bool, warn: int, block: int, shelf: int|null, mfg: bool}> */
    private array $rules = [];

    /**
     * @return array{track: bool, warn: int, block: int, shelf: int|null, mfg: bool}
     */
    public function rules(int $variantId): array
    {
        if (! isset($this->rules[$variantId])) {
            $product = Product::query()->withTrashed()
                ->whereKey(ProductVariant::query()->withTrashed()->whereKey($variantId)->value('product_id'))
                ->first(['id', 'track_lots', 'expiry_warn_days', 'expiry_block_days', 'shelf_life_months', 'require_mfg_date']);
            $this->rules[$variantId] = [
                'track' => (bool) $product?->track_lots,
                'warn' => $product?->expiry_warn_days ?? self::DEFAULT_WARN_DAYS,
                'block' => $product?->expiry_block_days ?? 0,
                'shelf' => $product?->shelf_life_months,
                'mfg' => (bool) $product?->require_mfg_date,
            ];
        }

        return $this->rules[$variantId];
    }

    public function tracked(int $variantId): bool
    {
        return $this->rules($variantId)['track'];
    }

    /** Settings may change between operations of a long-running worker. */
    public function forget(): void
    {
        $this->rules = [];
    }

    public static function today(): string
    {
        return ReportRange::now()->toDateString();
    }

    /** A lot is not sold when its expiry date is before today + the stop-selling days (0 = once expired). */
    public static function blockedFrom(int $blockDays, ?string $today = null): string
    {
        return Carbon::parse($today ?? self::today())->addDays(max(0, $blockDays))->toDateString();
    }

    public function isBlocked(StockLot $lot): bool
    {
        return $lot->expiry_date !== null
            && $lot->expiry_date->toDateString() < self::blockedFrom($this->rules($lot->product_variant_id)['block']);
    }

    /** Free quantity (on hand − held) in lots that may not be sold. Reservations must not take it. */
    public function blockedFree(int $warehouseId, int $variantId): int
    {
        if (! $this->tracked($variantId)) {
            return 0;
        }

        return (int) StockLot::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)
            ->whereNotNull('expiry_date')->where('expiry_date', '<', self::blockedFrom($this->rules($variantId)['block']))
            ->where('on_hand', '>', 0)->get(['on_hand', 'reserved'])->sum(fn (StockLot $l) => $l->free());
    }

    /**
     * blockedFree() for many (warehouse, SKU) pairs at once (publishing stock to marketplaces).
     *
     * @param  array<int, int>  $warehouseIds
     * @param  array<int, int>  $variantIds
     * @return array<string, int> "warehouse:variant" => free quantity in blocked lots
     */
    public function blockedFreeMap(array $warehouseIds, array $variantIds): array
    {
        if ($warehouseIds === [] || $variantIds === []) {
            return [];
        }

        return StockLot::query()
            ->join('product_variants as v', 'v.id', '=', 'stock_lots.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.track_lots', true)
            ->whereIn('stock_lots.warehouse_id', $warehouseIds)->whereIn('stock_lots.product_variant_id', $variantIds)
            ->whereNotNull('stock_lots.expiry_date')
            ->whereRaw('stock_lots.expiry_date < DATE_ADD(?, INTERVAL COALESCE(p.expiry_block_days, 0) DAY)', [self::today()])
            ->groupBy('stock_lots.warehouse_id', 'stock_lots.product_variant_id')
            ->selectRaw('stock_lots.warehouse_id, stock_lots.product_variant_id, SUM(GREATEST(stock_lots.on_hand - CAST(stock_lots.reserved AS SIGNED), 0)) as qty')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->warehouse_id}:{$r->product_variant_id}" => (int) $r->qty])
            ->all();
    }

    /**
     * Lots in stock of the current tenant that may not be sold any more (expired or under the stop-selling days)
     * and that enter the warning window of their product.
     *
     * @return array{blocked: int, expiring: int, first: array{name: string, lot_no: string, expiry_date: string}|null}
     */
    public function alertCounts(): array
    {
        $today = self::today();
        $base = fn () => StockLot::query()
            ->join('product_variants as v', 'v.id', '=', 'stock_lots.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.track_lots', true)->whereNull('v.deleted_at')
            ->where('stock_lots.on_hand', '>', 0)->whereNotNull('stock_lots.expiry_date');
        $blockedSql = 'stock_lots.expiry_date < DATE_ADD(?, INTERVAL COALESCE(p.expiry_block_days, 0) DAY)';
        $blocked = $base()->whereRaw($blockedSql, [$today])->count();
        $expiring = $base()->whereRaw("NOT ({$blockedSql})", [$today])
            ->whereRaw('stock_lots.expiry_date <= DATE_ADD(?, INTERVAL COALESCE(p.expiry_warn_days, ?) DAY)', [$today, self::DEFAULT_WARN_DAYS])->count();
        $first = $base()->orderBy('stock_lots.expiry_date')->first(['p.name', 'stock_lots.lot_no', 'stock_lots.expiry_date']);

        return [
            'blocked' => $blocked,
            'expiring' => $expiring,
            'first' => $first ? ['name' => (string) $first->getAttribute('name'), 'lot_no' => $first->lot_no, 'expiry_date' => $first->expiry_date->format('d/m/Y')] : null,
        ];
    }

    /**
     * Applies the lot part of a movement just written by InventoryService.
     *
     * @param  array<int, array{lot_no?: string|null, mfg_date?: string|null, expiry_date?: string|null, quantity: int}>|null  $lots
     */
    public function apply(StockBalance $balance, StockMovement $movement, int $onHandDelta, int $reservedDelta, ?StockReservation $reservation, ?array $lots): void
    {
        $variantId = $balance->product_variant_id;
        if (! $this->tracked($variantId) || ($onHandDelta === 0 && $reservedDelta === 0)) {
            return;
        }
        $warehouseId = $balance->warehouse_id;

        // Holds: take sellable lots FEFO; release / ship what the hold took.
        if ($reservation !== null && $reservedDelta > 0 && $onHandDelta === 0) {
            foreach ($this->fefo($warehouseId, $variantId, $reservedDelta, sellableOnly: true) as [$lot, $qty]) {
                $this->post($lot, $movement, 0, $qty, $reservation);
            }

            return;
        }
        if ($reservation !== null && $reservedDelta < 0) {
            $remaining = -$reservedDelta;
            foreach ($this->held($reservation) as $lotId => $qty) {
                $take = min($qty, $remaining);
                if ($take <= 0) {
                    break;
                }
                $lot = StockLot::query()->whereKey($lotId)->lockForUpdate()->firstOrFail();
                $this->post($lot, $movement, $onHandDelta < 0 ? -$take : 0, -$take, $reservation);
                $remaining -= $take;
            }
            if ($remaining > 0) {
                // A hold made before the product was tracked: the goods leave from "Chưa rõ lô".
                $this->post($this->lot($warehouseId, $variantId, StockLot::UNKNOWN), $movement, $onHandDelta < 0 ? -$remaining : 0, 0, null);
            }

            return;
        }

        if ($onHandDelta > 0) {
            $remaining = $onHandDelta;
            foreach ($this->normalize($variantId, $lots ?? []) as $input) {
                $take = min($input['quantity'], $remaining);
                if ($take <= 0) {
                    continue;
                }
                $lot = $this->lot($warehouseId, $variantId, $input['lot_no'], $input['mfg_date'], $input['expiry_date']);
                $this->post($lot, $movement, $take, 0, null);
                $remaining -= $take;
            }
            if ($remaining > 0) {
                $this->post($this->lot($warehouseId, $variantId, StockLot::UNKNOWN), $movement, $remaining, 0, null);
            }

            return;
        }

        if ($onHandDelta < 0) {
            $remaining = -$onHandDelta;
            foreach ($this->normalize($variantId, $lots ?? [], requireExisting: true) as $input) {
                $lot = StockLot::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)
                    ->where('lot_no', $input['lot_no'])->lockForUpdate()->first()
                    ?? throw new InvalidStockOperation("Lô {$input['lot_no']} không có trong kho.");
                $take = min($input['quantity'], $remaining);
                if ($take > $lot->free()) {
                    throw new InvalidStockOperation("Lô {$lot->label()} chỉ còn {$lot->free()} có thể xuất.");
                }
                $this->post($lot, $movement, -$take, 0, null);
                $remaining -= $take;
            }
            if ($remaining > 0) {
                // Selling without a hold must not take expired lots; moving or writing off goods may.
                $sellable = $movement->type === StockMovement::SHIP_UNRESERVED;
                foreach ($this->fefo($warehouseId, $variantId, $remaining, sellableOnly: $sellable) as [$lot, $qty]) {
                    $this->post($lot, $movement, -$qty, 0, null);
                }
            }
        }
    }

    /**
     * Moves free quantity between lots of one (warehouse, SKU) without changing the balance: splitting "Chưa rõ
     * lô" into real lots after a count, or correcting a lot. The target lot is created when missing.
     *
     * @param  array{lot_no?: string|null, mfg_date?: string|null, expiry_date?: string|null}  $target
     */
    public function move(StockBalance $balance, StockMovement $movement, StockLot $from, array $target, int $quantity): StockLot
    {
        if ($quantity <= 0) {
            throw new InvalidStockOperation('Số lượng chuyển lô phải lớn hơn 0.');
        }
        $from = StockLot::query()->whereKey($from->id)->lockForUpdate()->firstOrFail();
        if ($from->warehouse_id !== $balance->warehouse_id || $from->product_variant_id !== $balance->product_variant_id) {
            throw new InvalidStockOperation('Lô không thuộc kho / SKU này.');
        }
        if ($quantity > $from->free()) {
            throw new InvalidStockOperation("Lô {$from->label()} chỉ còn {$from->free()} chưa giữ cho đơn.");
        }
        $input = $this->normalize($balance->product_variant_id, [$target + ['quantity' => $quantity]])[0];
        $to = $this->lot($balance->warehouse_id, $balance->product_variant_id, $input['lot_no'], $input['mfg_date'], $input['expiry_date']);
        if ($to->id === $from->id) {
            throw new InvalidStockOperation('Lô đích trùng lô nguồn.');
        }
        $this->post($from, $movement, -$quantity, 0, null);
        $this->post($to, $movement, $quantity, 0, null);

        return $to;
    }

    /**
     * Brings the lots of a (warehouse, SKU) back in line with its balance when tracking starts (or starts again
     * after a pause): stock not in any lot goes to "Chưa rõ lô", holds of active reservations are taken from it,
     * and holds left by reservations that ended while tracking was off are dropped.
     */
    public function syncWithBalance(StockBalance $balance, StockMovement $movement): void
    {
        $warehouseId = $balance->warehouse_id;
        $variantId = $balance->product_variant_id;

        $stale = StockLotMovement::query()->join('stock_reservations as r', 'r.id', '=', 'stock_lot_movements.stock_reservation_id')
            ->join('stock_lots as l', 'l.id', '=', 'stock_lot_movements.stock_lot_id')
            ->where('l.warehouse_id', $warehouseId)->where('l.product_variant_id', $variantId)->where('r.status', '!=', StockReservation::ACTIVE)
            ->groupBy('stock_lot_movements.stock_reservation_id', 'stock_lot_movements.stock_lot_id')
            ->havingRaw('SUM(stock_lot_movements.reserved_delta) > 0')
            ->selectRaw('stock_lot_movements.stock_reservation_id as rid, stock_lot_movements.stock_lot_id as lid, SUM(stock_lot_movements.reserved_delta) as qty')->get();
        foreach ($stale as $row) {
            $lot = StockLot::query()->whereKey($row->lid)->lockForUpdate()->firstOrFail();
            $this->post($lot, $movement, 0, -min((int) $row->qty, $lot->reserved), StockReservation::query()->find($row->rid));
        }

        $inLots = (int) StockLot::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)->sum('on_hand');
        $diff = $balance->on_hand - $inLots;
        if ($diff > 0) {
            $this->post($this->lot($warehouseId, $variantId, StockLot::UNKNOWN), $movement, $diff, 0, null);
        } elseif ($diff < 0) {
            foreach ($this->fefo($warehouseId, $variantId, -$diff, sellableOnly: false) as [$lot, $qty]) {
                $this->post($lot, $movement, -$qty, 0, null);
            }
        }

        $active = StockReservation::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)
            ->where('status', StockReservation::ACTIVE)->where('quantity', '>', 0)->orderBy('id')->get();
        foreach ($active as $reservation) {
            $missing = $reservation->quantity - array_sum($this->held($reservation));
            if ($missing > 0) {
                foreach ($this->fefo($warehouseId, $variantId, $missing, sellableOnly: false) as [$lot, $qty]) {
                    $this->post($lot, $movement, 0, $qty, $reservation);
                }
            }
        }
    }

    /** Changes the dates of a lot (a typo on the receipt). The lot number itself changes through move(). */
    public function redate(StockLot $lot, ?string $mfgDate, ?string $expiryDate): StockLot
    {
        $lot = StockLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
        if ($lot->lot_no === StockLot::UNKNOWN) {
            throw new InvalidStockOperation('"Chưa rõ lô" không có hạn sử dụng. Hãy tách sang một lô có số lô.');
        }
        $this->assertDates($mfgDate, $expiryDate);
        $lot->forceFill(['mfg_date' => $mfgDate, 'expiry_date' => $expiryDate])->save();

        return $lot;
    }

    /**
     * Replaces the lots a hold takes (staff picked other lots for an order line). Same total quantity.
     *
     * @param  array<int, int>  $wanted  lot id => quantity
     */
    public function reallocate(StockReservation $reservation, StockMovement $movement, array $wanted): void
    {
        $wanted = array_filter($wanted, fn ($q) => $q > 0);
        if (array_sum($wanted) !== $reservation->quantity) {
            throw new InvalidStockOperation("Tổng số lấy từ các lô phải bằng {$reservation->quantity}.");
        }
        $current = $this->held($reservation);
        $ids = array_unique([...array_keys($current), ...array_keys($wanted)]);
        sort($ids);
        $lots = StockLot::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Give back first, then take, so a lot can be both.
        foreach ($current as $lotId => $qty) {
            $diff = ($wanted[$lotId] ?? 0) - $qty;
            if ($diff < 0) {
                $this->post($lots[$lotId], $movement, 0, $diff, $reservation);
            }
        }
        foreach ($wanted as $lotId => $qty) {
            $lot = $lots->get($lotId);
            if (! $lot || $lot->warehouse_id !== $reservation->warehouse_id || $lot->product_variant_id !== $reservation->product_variant_id) {
                throw new InvalidStockOperation('Lô không thuộc kho / SKU của dòng hàng.');
            }
            $diff = $qty - ($current[$lotId] ?? 0);
            if ($diff <= 0) {
                continue;
            }
            if ($this->isBlocked($lot)) {
                throw new InvalidStockOperation("Lô {$lot->label()} đã hết hạn hoặc dưới ngưỡng ngừng bán.");
            }
            if ($diff > $lot->free()) {
                throw new InvalidStockOperation("Lô {$lot->label()} chỉ còn {$lot->free()} có thể lấy.");
            }
            $this->post($lot, $movement, 0, $diff, $reservation);
        }
    }

    /**
     * Lots a hold currently takes.
     *
     * @return array<int, int> lot id => quantity (FEFO order of the lots)
     */
    public function held(StockReservation $reservation): array
    {
        return StockLotMovement::query()->where('stock_reservation_id', $reservation->id)
            ->groupBy('stock_lot_id')->selectRaw('stock_lot_id, SUM(reserved_delta) as qty')
            ->havingRaw('SUM(reserved_delta) > 0')->pluck('qty', 'stock_lot_id')
            ->map(fn ($q) => (int) $q)->sortKeys()->all();
    }

    /**
     * Checks the lots of goods-in lines before anything is written: every lot-tracked SKU needs its whole quantity
     * split into lots with a lot number and an expiry date (or a manufacturing date + the product's shelf life).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, string> validation errors keyed lines.{i}.lots
     */
    public function incomingErrors(array $lines): array
    {
        $errors = [];
        foreach (array_values($lines) as $i => $line) {
            $variantId = (int) $line['variant_id'];
            if (! $this->tracked($variantId)) {
                continue;
            }
            $rules = $this->rules($variantId);
            $sku = (string) ProductVariant::query()->withTrashed()->whereKey($variantId)->value('sku');
            $lots = array_values(array_filter($line['lots'] ?? [], fn ($l) => (int) ($l['quantity'] ?? 0) > 0));
            $key = "lines.{$i}.lots";
            if ($lots === []) {
                $errors[$key] = "{$sku} quản lý theo lô: nhập số lô và hạn sử dụng.";

                continue;
            }
            foreach ($lots as $lot) {
                if (trim((string) ($lot['lot_no'] ?? '')) === '') {
                    $errors[$key] = "{$sku}: thiếu số lô.";
                } elseif ($rules['mfg'] && empty($lot['mfg_date'])) {
                    $errors[$key] = "{$sku}: lô {$lot['lot_no']} thiếu ngày sản xuất.";
                } elseif (empty($lot['expiry_date']) && ! (! empty($lot['mfg_date']) && $rules['shelf'])) {
                    $errors[$key] = "{$sku}: lô {$lot['lot_no']} thiếu hạn sử dụng.";
                }
            }
            $sum = array_sum(array_map(fn ($l) => (int) $l['quantity'], $lots));
            if (! isset($errors[$key]) && $sum !== (int) $line['quantity']) {
                $errors[$key] = "{$sku}: tổng số lượng các lô ({$sum}) phải bằng số nhập ({$line['quantity']}).";
            }
        }

        return $errors;
    }

    /**
     * The lots each movement went in or out of (documents keep it: transfer lines, receipts).
     *
     * @param  array<int, int>  $movementIds
     * @return array<int, array<int, array{lot_no: string, mfg_date: string|null, expiry_date: string|null, quantity: int}>> movement id => lots
     */
    public function splitOf(array $movementIds): array
    {
        if ($movementIds === []) {
            return [];
        }
        $out = [];
        $rows = StockLotMovement::query()->with('lot')->whereIn('stock_movement_id', $movementIds)->orderBy('id')->get();
        foreach ($rows as $row) {
            $lot = $row->lot;
            $qty = abs($row->on_hand_delta) ?: abs($row->reserved_delta);
            if ($qty === 0) {
                continue;
            }
            $out[$row->stock_movement_id][] = [
                'lot_no' => $lot->lot_no,
                'mfg_date' => $lot->mfg_date?->toDateString(),
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'quantity' => $qty,
            ];
        }

        return $out;
    }

    /**
     * @return Collection<int, StockLot>
     */
    public function lotsOf(int $warehouseId, int $variantId): Collection
    {
        return StockLot::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)
            ->orderByRaw("lot_no <> '' , expiry_date IS NULL, expiry_date, id")->get();
    }

    // ------------------------------------------------------------------ internals

    /**
     * FEFO split of a quantity over the free part of the lots. Whatever the lots cannot cover comes from
     * "Chưa rõ lô" (which may then go negative, as a warehouse that allows negative stock does).
     *
     * @return array<int, array{0: StockLot, 1: int}>
     */
    private function fefo(int $warehouseId, int $variantId, int $quantity, bool $sellableOnly): array
    {
        $lots = StockLot::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)
            ->orderByRaw("lot_no <> '' , expiry_date IS NULL, expiry_date, id")->lockForUpdate()->get();

        $split = [];
        $remaining = $quantity;
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            if ($sellableOnly && $this->isBlocked($lot)) {
                continue;
            }
            $take = min($lot->free(), $remaining);
            if ($take > 0) {
                $split[] = [$lot, $take];
                $remaining -= $take;
            }
        }
        if ($remaining > 0) {
            $unknown = $lots->firstWhere('lot_no', StockLot::UNKNOWN) ?? $this->lot($warehouseId, $variantId, StockLot::UNKNOWN);
            $found = false;
            foreach ($split as $i => [$lot, $qty]) {
                if ($lot->id === $unknown->id) {
                    $split[$i][1] += $remaining;
                    $found = true;
                }
            }
            if (! $found) {
                $split[] = [$unknown, $remaining];
            }
        }

        return $split;
    }

    /** Finds or creates a lot (locked). A known lot keeps its dates unless it had none. */
    private function lot(int $warehouseId, int $variantId, string $lotNo, ?string $mfgDate = null, ?string $expiryDate = null): StockLot
    {
        $find = fn () => StockLot::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)
            ->where('lot_no', $lotNo)->lockForUpdate()->first();

        $lot = $find();
        if ($lot === null) {
            $lot = new StockLot;
            $lot->forceFill([
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $variantId,
                'lot_no' => $lotNo,
                'mfg_date' => $lotNo === StockLot::UNKNOWN ? null : $mfgDate,
                'expiry_date' => $lotNo === StockLot::UNKNOWN ? null : $expiryDate,
                'first_received_at' => now(),
            ])->save();

            return $lot;
        }

        if ($lotNo !== StockLot::UNKNOWN && (($lot->expiry_date === null && $expiryDate) || ($lot->mfg_date === null && $mfgDate))) {
            $lot->forceFill(['expiry_date' => $lot->expiry_date ?? $expiryDate, 'mfg_date' => $lot->mfg_date ?? $mfgDate])->save();
        }

        return $lot;
    }

    private function post(StockLot $lot, StockMovement $movement, int $onHandDelta, int $reservedDelta, ?StockReservation $reservation): void
    {
        if ($onHandDelta === 0 && $reservedDelta === 0) {
            return;
        }
        $reserved = $lot->reserved + $reservedDelta;
        if ($reserved < 0) {
            throw new InvalidStockOperation("Lô {$lot->label()}: số đang giữ không thể âm.");
        }
        $lot->forceFill(['on_hand' => $lot->on_hand + $onHandDelta, 'reserved' => $reserved])->save();

        (new StockLotMovement)->forceFill([
            'stock_lot_id' => $lot->id,
            'stock_movement_id' => $movement->id,
            'stock_reservation_id' => $reservation?->id,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
        ])->save();
    }

    /**
     * Cleans lot input: trims the lot number, fills the expiry date from the manufacturing date and the product's
     * shelf life, merges repeated lot numbers.
     *
     * @param  array<int, array<string, mixed>>  $lots
     * @return array<int, array{lot_no: string, mfg_date: string|null, expiry_date: string|null, quantity: int}>
     */
    private function normalize(int $variantId, array $lots, bool $requireExisting = false): array
    {
        $rules = $this->rules($variantId);
        $out = [];
        foreach ($lots as $input) {
            $lotNo = mb_substr(trim((string) ($input['lot_no'] ?? '')), 0, 64);
            $qty = (int) ($input['quantity'] ?? 0);
            if ($qty < 0) {
                throw new InvalidStockOperation('Số lượng của lô không được âm.');
            }
            $mfg = $this->date($input['mfg_date'] ?? null);
            $expiry = $this->date($input['expiry_date'] ?? null);
            if (! $requireExisting && $expiry === null && $mfg !== null && $rules['shelf']) {
                $expiry = Carbon::parse($mfg)->addMonthsNoOverflow($rules['shelf'])->toDateString();
            }
            $this->assertDates($mfg, $expiry);

            if (isset($out[$lotNo])) {
                $out[$lotNo]['quantity'] += $qty;
            } else {
                $out[$lotNo] = ['lot_no' => $lotNo, 'mfg_date' => $mfg, 'expiry_date' => $expiry, 'quantity' => $qty];
            }
        }

        return array_values($out);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidStockOperation("Ngày không hợp lệ: {$value}.");
        }
    }

    private function assertDates(?string $mfg, ?string $expiry): void
    {
        if ($mfg !== null && $expiry !== null && $expiry <= $mfg) {
            throw new InvalidStockOperation('Hạn sử dụng phải sau ngày sản xuất.');
        }
    }
}
