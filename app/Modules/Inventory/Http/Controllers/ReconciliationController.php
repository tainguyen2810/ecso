<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * "Đối soát tồn kho" (audit round D): what the nightly ledger check found for this business, and orders handed over
 * while stock was short (the missing part was not taken out of stock). Read-only: corrections go through adjustments.
 */
class ReconciliationController extends Controller
{
    private const PROBLEMS = [
        'on_hand_vs_ledger' => 'Tồn khác tổng sổ kho',
        'reserved_vs_ledger' => 'Số đang giữ khác tổng sổ kho',
        'reserved_vs_reservations' => 'Số đang giữ khác các đơn đang giữ',
        'negative_reserved' => 'Số đang giữ bị âm',
        'negative_on_hand' => 'Tồn bị âm',
        'ledger_without_balance' => 'Có sổ kho nhưng không có dòng tồn',
    ];

    public function __invoke(TenantContext $context, StockVerifier $verifier): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);
        $tenant = $context->id();

        $problems = collect($verifier->verify($tenant));
        $names = $this->names($tenant, $problems->pluck('warehouse_id')->all(), $problems->pluck('product_variant_id')->all());
        $ledger = $problems->map(fn ($p) => [
            'warehouse' => $names['warehouses'][$p['warehouse_id']] ?? null,
            'variant' => $names['variants'][$p['product_variant_id']] ?? null,
            'problem' => $p['problem'],
            'label' => self::PROBLEMS[$p['problem']] ?? $p['problem'],
            'expected' => $p['expected'],
            'actual' => $p['actual'],
        ])->values();

        // Handed over short in the last 90 days and not filled since (FillOrderShortages fills them when stock comes in).
        $short = DB::table('stock_reservations as r')
            ->join('order_items as i', 'i.id', '=', 'r.reference_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('r.tenant_id', $tenant)->where('r.reference_type', 'order_item')
            ->where('r.status', StockReservation::CONSUMED)->where('r.shortage', '>', 0)
            ->where('r.consumed_at', '>=', now()->subDays(90))
            ->orderByDesc('r.consumed_at')->limit(200)
            ->get(['r.warehouse_id', 'r.product_variant_id', 'r.quantity', 'r.shortage', 'r.consumed_at', 'o.ulid as order_id', 'o.external_order_id', 'o.marketplace']);
        $names = $this->names($tenant, $short->pluck('warehouse_id')->all(), $short->pluck('product_variant_id')->all());

        return response()->json(['data' => [
            'checked_at' => now()->toIso8601String(),
            'ledger' => $ledger,
            'shortages' => $short->map(fn ($r) => [
                'order' => ['id' => $r->order_id, 'number' => $r->external_order_id, 'marketplace' => $r->marketplace],
                'warehouse' => $names['warehouses'][$r->warehouse_id] ?? null,
                'variant' => $names['variants'][$r->product_variant_id] ?? null,
                'quantity' => (int) $r->quantity,
                'shortage' => (int) $r->shortage,
                'shipped_at' => $r->consumed_at,
            ])->values(),
        ]]);
    }

    /**
     * @param  array<int, int>  $warehouseIds
     * @param  array<int, int>  $variantIds
     * @return array{warehouses: array<int, array{id: string, code: string, name: string}>, variants: array<int, array{id: string, sku: string, name: string}>}
     */
    private function names(int $tenant, array $warehouseIds, array $variantIds): array
    {
        return [
            'warehouses' => DB::table('warehouses')->where('tenant_id', $tenant)->whereIn('id', $warehouseIds)->get(['id', 'ulid', 'code', 'name'])
                ->mapWithKeys(fn ($w) => [$w->id => ['id' => $w->ulid, 'code' => $w->code, 'name' => $w->name]])->all(),
            'variants' => DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('product_variants.tenant_id', $tenant)->whereIn('product_variants.id', $variantIds)
                ->get(['product_variants.id', 'product_variants.ulid', 'product_variants.sku', 'products.name'])
                ->mapWithKeys(fn ($v) => [$v->id => ['id' => $v->ulid, 'sku' => $v->sku, 'name' => $v->name]])->all(),
        ];
    }
}
