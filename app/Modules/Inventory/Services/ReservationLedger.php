<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ComboResolver;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * The Inventory module's API for other modules that hold stock against their own records (order items).
 * Callers pass plain ids and get plain arrays back; stock ledger models never leave the Inventory module.
 * Every write is delegated to InventoryService with a deterministic idempotency key (INVENTORY-ENGINE §4).
 *
 * A combo SKU holds one reservation per component (bundle_variant_id = the combo). Results are always given per
 * reference in the units of the referenced SKU: for a combo, how many whole combos are held / shipped.
 */
class ReservationLedger
{
    public function __construct(private readonly InventoryService $inventory, private readonly ComboResolver $combos) {}

    /**
     * Holds stock for one reference. Idempotent: an existing active or consumed reservation is returned as is.
     *
     * @return array{id: int, warehouse_id: int, quantity: int, shortage: int, status: string}
     */
    public function hold(string $referenceType, int $referenceId, Warehouse $warehouse, ProductVariant $variant, int $quantity): array
    {
        $parts = $this->combos->componentsOf([$variant->id])[$variant->id] ?? null;

        if ($parts === null) {
            $reservation = $this->inventory->reserve($warehouse, $variant, $quantity, $referenceType, $referenceId, "reserve:{$referenceType}:{$referenceId}:{$warehouse->id}");

            return $this->toArray($reservation);
        }

        $components = ProductVariant::query()->withTrashed()->whereIn('id', array_keys($parts))->get()->keyBy('id');
        $rows = [];
        // Components in id order: the same lock sequence as every other hold.
        foreach ($parts as $componentId => $per) {
            $rows[] = $this->inventory->reserve(
                $warehouse, $components[$componentId], $quantity * $per, $referenceType, $referenceId,
                "reserve:{$referenceType}:{$referenceId}:{$warehouse->id}:{$componentId}", true, $variant->id, $per,
            );
        }

        return $this->combine(collect($rows));
    }

    /**
     * Releases every active reservation of the references (order cancelled before shipping).
     *
     * @param  array<int, int>  $referenceIds
     */
    public function releaseAll(string $referenceType, array $referenceIds, ?string $reason = null): int
    {
        $count = 0;
        foreach ($this->active($referenceType, $referenceIds) as $reservation) {
            $this->inventory->release($reservation, "release:{$reservation->id}", $reason);
            $count++;
        }

        return $count;
    }

    /**
     * Ships the reserved quantity of each reference. Before shipping, a short reservation is topped up with what
     * is available now. Returns, per reference, the quantity shipped and the shortage left (not shipped from stock).
     * For a combo, unit_cost is the cost of one combo (sum of the components' costs) and shipped counts whole combos.
     *
     * @param  array<int, int>  $referenceIds
     * @return array<int, array{shipped: int, shortage: int, unit_cost: string|null, error: string|null}>
     */
    public function consumeAll(string $referenceType, array $referenceIds, ?string $reason = null): array
    {
        $parts = [];
        foreach ($this->active($referenceType, $referenceIds) as $reservation) {
            $reservation = $this->inventory->fillShortage($reservation);
            try {
                $reservation = $this->inventory->ship($reservation, "ship:{$reservation->id}", $reason);
                $parts[$reservation->reference_id][] = ['reservation' => $reservation, 'shipped' => $reservation->quantity, 'shortage' => $reservation->shortage, 'error' => null];
            } catch (InsufficientStock $e) {
                // On hand fell below reserved (a stocktake). Nothing is shipped from stock; the order is flagged.
                $parts[$reservation->reference_id][] = ['reservation' => $reservation, 'shipped' => 0, 'shortage' => $reservation->quantity + $reservation->shortage, 'error' => $e->getMessage()];
            }
        }

        $result = [];
        foreach ($parts as $referenceId => $rows) {
            $per = fn (array $r) => max(1, (int) ($r['reservation']->bundle_quantity ?? 1));
            $total = intdiv($rows[0]['shipped'] + $rows[0]['shortage'], $per($rows[0]));
            $shipped = min(array_map(fn ($r) => intdiv($r['shipped'], $per($r)), $rows));
            $error = collect($rows)->pluck('error')->filter()->first();

            $result[$referenceId] = [
                'shipped' => $shipped,
                'shortage' => $total - $shipped,
                'unit_cost' => $error === null ? $this->shippedCost(array_column($rows, 'reservation')) : null,
                'error' => $error,
            ];
        }

        return $result;
    }

    /**
     * Tops up the short active reservations of the references with what is available now and returns the shortage
     * left per reference (only references still short are listed).
     *
     * @param  array<int, int>  $referenceIds
     * @return array<int, int> reference id => shortage
     */
    public function topUp(string $referenceType, array $referenceIds): array
    {
        $rows = collect();
        foreach ($this->active($referenceType, $referenceIds) as $reservation) {
            $rows->push($this->inventory->fillShortage($reservation));
        }

        $short = [];
        foreach ($rows->groupBy('reference_id') as $referenceId => $group) {
            $shortage = $this->combine($group)['shortage'];
            if ($shortage > 0) {
                $short[(int) $referenceId] = $shortage;
            }
        }

        return $short;
    }

    /**
     * Tops up short active reservations of the given variants, oldest reference order first.
     *
     * @param  array<int, int>  $variantIds
     * @param  callable(array<int, int>): array<int, int>  $orderReferences  sorts reference ids by business priority
     * @return array<int, int> reference ids whose reservation changed
     */
    public function fillShortages(string $referenceType, array $variantIds, callable $orderReferences): array
    {
        $short = StockReservation::query()
            ->where('reference_type', $referenceType)
            ->whereIn('product_variant_id', $variantIds)
            ->where('status', StockReservation::ACTIVE)
            ->where('shortage', '>', 0)
            ->orderBy('product_variant_id')
            ->get()->groupBy('reference_id');

        $changed = [];
        foreach ($orderReferences($short->keys()->all()) as $referenceId) {
            foreach ($short[$referenceId] as $reservation) {
                $before = $reservation->quantity;
                if ($this->inventory->fillShortage($reservation)->quantity !== $before) {
                    $changed[(int) $referenceId] = (int) $referenceId;
                }
            }
        }

        return array_values($changed);
    }

    /**
     * Current reservation per reference (latest one if there were several; a combo's components combined).
     *
     * @param  array<int, int>  $referenceIds
     * @return array<int, array{id: int, warehouse_id: int, quantity: int, shortage: int, status: string}>
     */
    public function snapshot(string $referenceType, array $referenceIds): array
    {
        if ($referenceIds === []) {
            return [];
        }

        return StockReservation::query()
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', $referenceIds)
            ->orderBy('id')
            ->get()
            ->groupBy('reference_id')
            ->map(function (Collection $rows) {
                // Rows of the latest hold: a re-hold in another warehouse supersedes the earlier one.
                $latest = $rows->last();

                return $this->combine($rows->where('warehouse_id', $latest->warehouse_id)->where('bundle_variant_id', $latest->bundle_variant_id));
            })
            ->all();
    }

    /**
     * @param  array<int, int>  $referenceIds
     * @return iterable<StockReservation>
     */
    private function active(string $referenceType, array $referenceIds): iterable
    {
        if ($referenceIds === []) {
            return [];
        }

        return StockReservation::query()
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', $referenceIds)
            ->where('status', StockReservation::ACTIVE)
            ->orderBy('product_variant_id')->orderBy('id') // same lock sequence as hold()
            ->get();
    }

    /**
     * Cost of one unit of the referenced SKU at shipping: a single SKU's average cost, or for a combo the sum of
     * component cost × quantity per combo.
     *
     * @param  array<int, StockReservation>  $reservations
     */
    private function shippedCost(array $reservations): ?string
    {
        $costs = ProductVariant::query()->withTrashed()->whereIn('id', array_map(fn ($r) => $r->product_variant_id, $reservations))->pluck('cost_price', 'id');
        if (count($reservations) === 1 && $reservations[0]->bundle_variant_id === null) {
            return $costs[$reservations[0]->product_variant_id] ?? null;
        }

        $total = '0';
        foreach ($reservations as $r) {
            $total = bcadd($total, bcmul((string) ($costs[$r->product_variant_id] ?? '0'), (string) ($r->bundle_quantity ?? 1), 4), 4);
        }

        return $total;
    }

    /**
     * One reference's reservations as a single figure in the referenced SKU's units.
     *
     * @param  Collection<int, StockReservation>  $rows
     * @return array{id: int, warehouse_id: int, quantity: int, shortage: int, status: string}
     */
    private function combine(Collection $rows): array
    {
        $first = $rows->first();
        if ($rows->count() === 1 && $first->bundle_variant_id === null) {
            return $this->toArray($first);
        }

        $per = fn (StockReservation $r) => max(1, (int) $r->bundle_quantity);
        $total = intdiv($first->quantity + $first->shortage, $per($first));
        $held = $rows->min(fn (StockReservation $r) => intdiv($r->quantity, $per($r)));
        $statuses = $rows->pluck('status')->unique();
        $status = $statuses->count() === 1 ? $statuses->first()
            : ($statuses->contains(StockReservation::ACTIVE) ? StockReservation::ACTIVE : StockReservation::CONSUMED);

        return ['id' => $first->id, 'warehouse_id' => $first->warehouse_id, 'quantity' => $held, 'shortage' => $total - $held, 'status' => $status];
    }

    /**
     * @return array{id: int, warehouse_id: int, quantity: int, shortage: int, status: string}
     */
    private function toArray(StockReservation $r): array
    {
        return ['id' => $r->id, 'warehouse_id' => $r->warehouse_id, 'quantity' => $r->quantity, 'shortage' => $r->shortage, 'status' => $r->status];
    }
}
