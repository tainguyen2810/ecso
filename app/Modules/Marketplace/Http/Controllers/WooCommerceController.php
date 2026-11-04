<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Http\Resources\AccountResource;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\WooCommerceConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Connecting a WooCommerce store (see WooCommerceConnectService for the flow). */
class WooCommerceController extends Controller
{
    /** Step 1: returns the store's approval page to open. */
    public function authorizeStore(Request $request, WooCommerceConnectService $service): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate(['website' => ['required', 'string', 'max:255'], 'warehouse_id' => ['nullable', 'string']]);

        $result = $service->start($data['website'], $this->warehouse($data['warehouse_id'] ?? null), $request->user()->id);

        return response()->json(['data' => ['url' => $result['url']]]);
    }

    /** Step 2 (public, called by the store's server): Woo delivers the API keys the owner just approved. */
    public function receiveKeys(Request $request, WooCommerceConnectService $service): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();

        return $service->receiveKeys($payload)
            ? response()->json(['received' => true])
            : response()->json(['message' => 'Phiên kết nối không hợp lệ hoặc đã hết hạn.'], 400);
    }

    /** Step 3: the user is back from the store; finish the connection. */
    public function complete(Request $request, WooCommerceConnectService $service): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate(['state' => ['required', 'string', 'max:100']]);

        return $this->connected($service->complete($data['state'], $request->user()->id));
    }

    /** Fallback: the owner pastes REST API keys created by hand. */
    public function connectWithKeys(Request $request, WooCommerceConnectService $service): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'website' => ['required', 'string', 'max:255'],
            'consumer_key' => ['required', 'string', 'max:100', 'starts_with:ck_'],
            'consumer_secret' => ['required', 'string', 'max:100', 'starts_with:cs_'],
            'warehouse_id' => ['nullable', 'string'],
        ], [
            'consumer_key.starts_with' => 'Consumer key bắt đầu bằng ck_',
            'consumer_secret.starts_with' => 'Consumer secret bắt đầu bằng cs_',
        ]);

        return $this->connected($service->connectWithKeys($data['website'], $data['consumer_key'], $data['consumer_secret'], $this->warehouse($data['warehouse_id'] ?? null)));
    }

    /** @param  array{account: MarketplaceAccount, checks: array<string, mixed>}  $result */
    private function connected(array $result): JsonResponse
    {
        return (new AccountResource($result['account']->load('warehouse')))
            ->additional(['checks' => $result['checks']])->response()->setStatusCode(201);
    }

    private function warehouse(?string $ulid): ?Warehouse
    {
        return $ulid ? Warehouse::query()->where('ulid', $ulid)->where('status', 'active')->where('purpose', Warehouse::PURPOSE_SALE)->firstOrFail() : null;
    }
}
