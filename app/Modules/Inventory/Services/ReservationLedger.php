<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;

/**
 * The Inventory module's API for other modules that hold stock against their own records (order items).
 * Callers pass plain ids and get plain arrays back; stock ledger models never leave the Inventory module.
 * Every write is delegated to InventoryService with a deterministic idempotency key (INVENTORY-ENGINE §4).
 */
class ReservationLedger
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Holds stock for one reference. Idempotent: an existing active or consumed reservation is returned as is.
     *
     * @return array{id: int, warehouse_id: int, quantity: int, shortage: int, status: string}
     */
    public function hold(string $referenceType, int $referenceId, Warehouse $warehouse, ProductVariant $variant, int $quantity): array
    {
        $reservation = $this->inventory->reserve($warehouse, $variant, $quantity, $referenceType, $referenceId, "reserve:{$referenceType}:{$referenceId}:{$warehouse->id}");

        return $this->toArray($reservation);
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
     *
     * @param  array<int, int>  $referenceIds
     * @return array<int, array{shipped: int, shortage: int, unit_cost: string|null, error: string|null}>
     */
    public function consumeAll(string $referenceType, array $referenceIds, ?string $reason = null): array
    {
        $result = [];
        foreach ($this->active($referenceType, $referenceIds) as $reservation) {
            $reservation = $this->inventory->fillShortage($reservation);
            try {
                $reservation = $this->inventory->ship($reservation, "ship:{$reservation->id}", $reason);
                $result[$reservation->reference_id] = [
                    'shipped' => $reservation->quantity,
                    'shortage' => $reservation->shortage,
                    'unit_cost' => $this->shippedCost($reservation),
                    'error' => null,
                ];
            } catch (InsufficientStock $e) {
                // On hand fell below reserved (a stocktake). Nothing is shipped from stock; the order is flagged.
                $result[$reservation->reference_id] = ['shipped' => 0, 'shortage' => $reservation->quantity + $reservation->shortage, 'unit_cost' => null, 'error' => $e->getMessage()];
            }
        }

        return $result;
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
            ->get()->keyBy('reference_id');

        $changed = [];
        foreach ($orderReferences($short->keys()->all()) as $referenceId) {
            $reservation = $short[$referenceId];
            $before = $reservation->quantity;
            if ($this->inventory->fillShortage($reservation)->quantity !== $before) {
                $changed[] = (int) $referenceId;
            }
        }

        return $changed;
    }

    /**
     * Current reservation per reference (latest one if there were several).
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
            ->keyBy('reference_id')
            ->map(fn (StockReservation $r) => $this->toArray($r))
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

    private function shippedCost(StockReservation $reservation): ?string
    {
        return ProductVariant::query()->withTrashed()->whereKey($reservation->product_variant_id)->value('cost_price');
    }

    /**
     * @return array{id: int, warehouse_id: int, quantity: int, shortage: int, status: string}
     */
    private function toArray(StockReservation $r): array
    {
        return ['id' => $r->id, 'warehouse_id' => $r->warehouse_id, 'quantity' => $r->quantity, 'shortage' => $r->shortage, 'status' => $r->status];
    }
}
