<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Point of sale helpers. Sales themselves go through SalesOrderController (stage = completed). */
class PosController extends Controller
{
    /** SKUs the till keeps for offline selling. */
    public const CATALOG_LIMIT = 5000;

    /**
     * Today's figures of the current cashier, to count the cash drawer at the end of a shift: money is grouped by
     * method and by the day it was received (paid_at), refunds subtracted.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);
        $from = now()->startOfDay();
        $userId = $request->user()->id;

        $orders = Order::query()->where('marketplace', Order::SOURCE_MANUAL)->where('created_by', $userId)->where('placed_at', '>=', $from);
        $money = OrderPayment::query()->whereNull('voided_at')->where('created_by', $userId)->where('paid_at', '>=', $from)
            ->selectRaw("method, SUM(CASE WHEN kind = 'refund' THEN -amount ELSE amount END) as total")->groupBy('method')->pluck('total', 'method');

        return response()->json(['data' => [
            'orders' => (clone $orders)->where('status', '!=', OrderStatus::CANCELLED)->count(),
            'revenue' => (string) (clone $orders)->where('status', '!=', OrderStatus::CANCELLED)->sum('total'),
            'cancelled' => (clone $orders)->where('status', OrderStatus::CANCELLED)->count(),
            'by_method' => collect(OrderPayment::METHODS)->mapWithKeys(fn ($m) => [$m => bcadd((string) ($money[$m] ?? 0), '0', 2)]),
            'collected' => bcadd((string) $money->sum(), '0', 2),
            'since' => $from->toIso8601String(),
        ]]);
    }

    /**
     * Bán khi mất mạng: every SKU on sale with its price and the stock available in one warehouse, in one compact
     * list the till keeps on the device, so scanning and searching still work while the connection is down.
     */
    public function catalog(Request $request, StockReader $stock): JsonResponse
    {
        $this->authorize('create', Order::class);
        $data = $request->validate(['warehouse' => ['required', 'string']]);
        $warehouseId = DB::table('warehouses')->where('tenant_id', app(TenantContext::class)->id())->where('ulid', $data['warehouse'])->value('id')
            ?? abort(404);

        $variants = ProductVariant::query()->with('product.images')
            ->where('product_variants.status', Product::STATUS_ACTIVE)
            ->whereHas('product', fn ($p) => $p->where('status', Product::STATUS_ACTIVE))
            ->orderBy('sku')->limit(self::CATALOG_LIMIT + 1)->get();
        $truncated = $variants->count() > self::CATALOG_LIMIT;
        $variants = $variants->take(self::CATALOG_LIMIT);
        $available = $stock->available($variants->pluck('id')->map(fn ($id) => (int) $id)->all(), (int) $warehouseId);

        return response()->json([
            'data' => $variants->map(fn (ProductVariant $v) => [
                'id' => $v->ulid,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'name' => $v->name,
                'ref_price' => $v->ref_price,
                'wholesale_price' => $v->wholesale_price,
                'product' => ['id' => $v->product->ulid, 'name' => $v->product->name, 'image' => $v->product->images->first()?->url()],
                'stock' => ['available' => $available[(int) $v->id] ?? 0],
            ])->values(),
            'meta' => ['warehouse' => $data['warehouse'], 'at' => now()->toIso8601String(), 'truncated' => $truncated],
        ]);
    }
}
