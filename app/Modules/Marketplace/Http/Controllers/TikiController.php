<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Http\Resources\AccountResource;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\TikiConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kết nối shop Tiki (C8) with the Client ID and Secret of the shop's own In-house app. */
class TikiController extends Controller
{
    public function connect(Request $request, TikiConnectService $service): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'client_secret' => ['required', 'string', 'max:200'],
            'warehouse_id' => ['nullable', 'string'],
        ], ['client_id.required' => 'Nhập Client ID.', 'client_secret.required' => 'Nhập Client Secret.']);
        $warehouse = filled($data['warehouse_id'] ?? null)
            ? Warehouse::query()->where('ulid', $data['warehouse_id'])->where('status', 'active')->where('purpose', Warehouse::PURPOSE_SALE)->firstOrFail()
            : null;

        $account = $service->connect($data['client_id'], $data['client_secret'], $warehouse);

        return (new AccountResource($account->load('warehouse')))->response()->setStatusCode(201);
    }
}
