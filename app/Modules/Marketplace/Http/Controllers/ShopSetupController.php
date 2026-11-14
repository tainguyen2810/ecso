<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Http\Resources\AccountResource;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\ShopSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A5: "Thiết lập shop vừa kết nối" (pull products, link SKUs, opening stock, switch sync on). */
class ShopSetupController extends Controller
{
    public function show(MarketplaceAccount $account, ShopSetupService $setup): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);

        return response()->json(['data' => $setup->overview($account)]);
    }

    public function pull(MarketplaceAccount $account, ShopSetupService $setup): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $setup->pull($account);

        return response()->json(['data' => $setup->overview($account->refresh())], 202);
    }

    public function link(Request $request, MarketplaceAccount $account, ShopSetupService $setup): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate(['without_sku' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $setup->link($account, (bool) ($data['without_sku'] ?? true))]);
    }

    public function stock(Request $request, MarketplaceAccount $account, ShopSetupService $setup): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'source' => ['required', 'in:marketplace,ezsell,none'],
            'cost_percent' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json(['data' => $setup->openingStock($account, $data['source'], $data['cost_percent'] ?? null)]);
    }

    public function finish(Request $request, MarketplaceAccount $account, ShopSetupService $setup): AccountResource
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'settings' => ['sometimes', 'array'],
            ...collect(MarketplaceAccount::SETTING_KEYS)->mapWithKeys(fn ($k) => ["settings.{$k}" => ['sometimes', 'boolean']])->all(),
        ]);

        return new AccountResource($setup->finish($account, $data['settings'] ?? [])->load('warehouse'));
    }
}
