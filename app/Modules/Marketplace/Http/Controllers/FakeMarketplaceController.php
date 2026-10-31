<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Simulator controls for a FAKE shop (create orders, change their status on the "marketplace", inject failures).
 * Disabled unless FAKE_MARKETPLACE_ENABLED=true; refused for any non-fake shop.
 */
class FakeMarketplaceController extends Controller
{
    public function createOrders(Request $request, MarketplaceAccount $account, FakeMarketplace $fake): JsonResponse
    {
        $this->guard($account);
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', Rule::in(array_keys(FakeConnector::STATUSES))],
            'max_items' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sku_ids' => ['nullable', 'array', 'max:20'],
            'sku_ids.*' => ['string'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $skus = DB::table('fake_marketplace_products')->where('shop_id', $account->external_shop_id)
            ->when($data['sku_ids'] ?? null, fn ($q, $ids) => $q->whereIn('sku_id', $ids))
            ->where('price', '>', 0)->pluck('sku_id')->all();
        abort_if($skus === [], 422, 'Shop giả lập chưa có sản phẩm.');

        $created = [];
        for ($i = 0; $i < $data['count']; $i++) {
            $pick = (array) array_rand(array_flip($skus), min(count($skus), random_int(1, $data['max_items'] ?? 2)));
            $lines = array_map(fn ($sku) => ['sku_id' => $sku, 'quantity' => $data['quantity'] ?? random_int(1, 3)], $pick);
            $created[] = $fake->createOrder($account->external_shop_id, $lines, $data['status'] ?? 'fake_ready')['order_sn'];
        }

        return response()->json(['data' => ['orders' => $created]], 201);
    }

    public function setStatus(Request $request, MarketplaceAccount $account, string $orderSn, FakeMarketplace $fake): JsonResponse
    {
        $this->guard($account);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(FakeConnector::STATUSES))]]);
        abort_if($fake->find($account->external_shop_id, $orderSn) === null, 404);

        $fake->setStatus($orderSn, $data['status']);

        return response()->json(['data' => $fake->find($account->external_shop_id, $orderSn)]);
    }

    /** Replays the last webhook of an order (duplicate delivery test). */
    public function replay(MarketplaceAccount $account, string $orderSn, FakeMarketplace $fake): JsonResponse
    {
        $this->guard($account);
        abort_if($fake->find($account->external_shop_id, $orderSn) === null, 404);

        return response()->json(['data' => $fake->notify($orderSn, 'replay-'.$orderSn.'-'.md5((string) $fake->find($account->external_shop_id, $orderSn)['update_time']))]);
    }

    public function failure(Request $request, MarketplaceAccount $account): JsonResponse
    {
        $this->guard($account);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $key = "fake-marketplace:fail:{$account->external_shop_id}";
        $enabled ? Cache::put($key, true, 3600) : Cache::forget($key);

        return response()->json(['data' => ['failing' => $enabled]]);
    }

    public function state(MarketplaceAccount $account): JsonResponse
    {
        $this->guard($account);

        return response()->json(['data' => [
            'failing' => (bool) Cache::get("fake-marketplace:fail:{$account->external_shop_id}"),
            'statuses' => array_keys(FakeConnector::STATUSES),
            'products' => DB::table('fake_marketplace_products')->where('shop_id', $account->external_shop_id)->orderBy('id')
                ->get(['sku_id', 'seller_sku', 'title', 'variant_name', 'price', 'stock', 'stock_updated_at']),
        ]]);
    }

    private function guard(MarketplaceAccount $account): void
    {
        $this->authorize('connect', MarketplaceAccount::class);
        abort_unless(ConnectorRegistry::fakeEnabled() && $account->marketplace === FakeConnector::MARKETPLACE, 404);
    }
}
