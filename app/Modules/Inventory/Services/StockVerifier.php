<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Support\Facades\DB;

/**
 * Checks the ledger invariants for one tenant (or all tenants when called without context, e.g. from the console):
 *  - balance.on_hand  == Σ movements.on_hand_delta
 *  - balance.reserved == Σ movements.reserved_delta == Σ active reservations.quantity
 *  - reserved >= 0, on_hand >= 0 (unless warehouse.allow_negative)
 * Read-only: mismatches are reported, never "fixed" by rewriting history. Corrections go through adjustments.
 */
class StockVerifier
{
    /**
     * @return array<int, array{tenant_id: int, warehouse_id: int, product_variant_id: int, problem: string, expected: int, actual: int}>
     */
    public function verify(?int $tenantId = null): array
    {
        // @tenant-bypass: aggregate integrity check across the ledger, filtered explicitly by tenant when given.
        $movements = DB::table('stock_movements')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id')
            ->selectRaw('tenant_id, warehouse_id, product_variant_id, SUM(on_hand_delta) as on_hand, SUM(reserved_delta) as reserved')
            ->get()->keyBy(fn ($r) => "{$r->tenant_id}:{$r->warehouse_id}:{$r->product_variant_id}");

        $reservations = DB::table('stock_reservations')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', StockReservation::ACTIVE)
            ->groupBy('tenant_id', 'warehouse_id', 'product_variant_id')
            ->selectRaw('tenant_id, warehouse_id, product_variant_id, SUM(quantity) as quantity')
            ->get()->keyBy(fn ($r) => "{$r->tenant_id}:{$r->warehouse_id}:{$r->product_variant_id}");

        $balances = DB::table('stock_balances')
            ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->when($tenantId, fn ($q) => $q->where('stock_balances.tenant_id', $tenantId))
            ->get(['stock_balances.*', 'warehouses.allow_negative']);

        // Lot-tracked SKUs: the lots of a warehouse add up to its balance.
        $lots = DB::table('stock_lots')
            ->join('product_variants', 'product_variants.id', '=', 'stock_lots.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.track_lots', true)
            ->when($tenantId, fn ($q) => $q->where('stock_lots.tenant_id', $tenantId))
            ->groupBy('stock_lots.tenant_id', 'stock_lots.warehouse_id', 'stock_lots.product_variant_id')
            ->selectRaw('stock_lots.tenant_id, stock_lots.warehouse_id, stock_lots.product_variant_id, SUM(stock_lots.on_hand) as on_hand, SUM(stock_lots.reserved) as reserved')
            ->get()->keyBy(fn ($r) => "{$r->tenant_id}:{$r->warehouse_id}:{$r->product_variant_id}");

        $problems = [];
        $seen = [];

        foreach ($balances as $b) {
            $k = "{$b->tenant_id}:{$b->warehouse_id}:{$b->product_variant_id}";
            $seen[$k] = true;
            $ledger = $movements[$k] ?? null;
            $reserved = (int) ($reservations[$k]->quantity ?? 0);

            $checks = [
                'on_hand_vs_ledger' => [(int) ($ledger->on_hand ?? 0), (int) $b->on_hand],
                'reserved_vs_ledger' => [(int) ($ledger->reserved ?? 0), (int) $b->reserved],
                'reserved_vs_reservations' => [$reserved, (int) $b->reserved],
            ];
            foreach ($checks as $problem => [$expected, $actual]) {
                if ($expected !== $actual) {
                    $problems[] = $this->problem($b, $problem, $expected, $actual);
                }
            }
            if (isset($lots[$k])) {
                if ((int) $lots[$k]->on_hand !== (int) $b->on_hand) {
                    $problems[] = $this->problem($b, 'lots_vs_on_hand', (int) $b->on_hand, (int) $lots[$k]->on_hand);
                }
                if ((int) $lots[$k]->reserved !== (int) $b->reserved) {
                    $problems[] = $this->problem($b, 'lots_vs_reserved', (int) $b->reserved, (int) $lots[$k]->reserved);
                }
            }
            if ((int) $b->reserved < 0) {
                $problems[] = $this->problem($b, 'negative_reserved', 0, (int) $b->reserved);
            }
            if ((int) $b->on_hand < 0 && ! $b->allow_negative) {
                $problems[] = $this->problem($b, 'negative_on_hand', 0, (int) $b->on_hand);
            }
        }

        foreach ($movements as $k => $m) {
            if (! isset($seen[$k])) {
                $problems[] = $this->problem($m, 'ledger_without_balance', (int) $m->on_hand, 0);
            }
        }

        return $problems;
    }

    private function problem(object $row, string $problem, int $expected, int $actual): array
    {
        return [
            'tenant_id' => (int) $row->tenant_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'product_variant_id' => (int) $row->product_variant_id,
            'problem' => $problem,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }
}
