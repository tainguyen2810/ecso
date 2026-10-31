<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Http\Resources\AccountResource;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\AccountService;
use App\Modules\Marketplace\Services\InventorySyncPlanner;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketplaceAccountController extends Controller
{
    /** Marketplaces we know of, with the capability matrix of their connector. */
    public function catalog(ConnectorRegistry $connectors): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);

        $data = [];
        foreach (config('marketplaces.catalog') as $key => $name) {
            $available = $connectors->has($key);
            if ($key === 'fake' && ! $available) {
                continue;
            }
            $capabilities = $available ? $connectors->for($key)->capabilities() : Capability::allUnknown();
            $data[] = [
                'key' => $key,
                'name' => $name,
                'available' => $available,
                'is_fake' => $key === 'fake',
                'capabilities' => $capabilities,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MarketplaceAccount::class);

        $accounts = MarketplaceAccount::query()->with('warehouse')
            ->withCount([
                'listingVariants as listings_total',
                'listingVariants as listings_mapped' => fn ($q) => $q->where('mapping_status', ListingVariant::MAPPED),
                'listingVariants as listings_suggested' => fn ($q) => $q->where('mapping_status', ListingVariant::SUGGESTED),
                'listingVariants as push_errors' => fn ($q) => $q->whereIn('push_status', ['rejected', 'failed', 'retrying']),
            ])
            ->addSelect(['pending_push' => InventorySyncOutbox::query()->selectRaw('count(*)')
                ->whereColumn('marketplace_account_id', 'marketplace_accounts.id')->where('status', InventorySyncOutbox::PENDING)])
            ->orderBy('id')->get();

        return AccountResource::collection($accounts);
    }

    public function connectFake(Request $request, AccountService $service): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'string'],
        ]);
        $warehouse = isset($data['warehouse_id']) ? Warehouse::query()->where('ulid', $data['warehouse_id'])->firstOrFail() : null;

        $account = $service->connectFake($data['shop_name'], $warehouse);

        return (new AccountResource($account->load('warehouse')))->response()->setStatusCode(201);
    }

    public function update(Request $request, MarketplaceAccount $account, AccountService $service): AccountResource
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'shop_name' => ['sometimes', 'string', 'max:100'],
            'warehouse_id' => ['sometimes', 'string'],
            'sync_enabled' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['warehouse_id'])) {
            $data['warehouse_id'] = Warehouse::query()->where('ulid', $data['warehouse_id'])->where('status', 'active')->firstOrFail()->id;
        }

        return new AccountResource($service->update($account, $data)->load('warehouse'));
    }

    public function disconnect(MarketplaceAccount $account, AccountService $service): AccountResource
    {
        $this->authorize('connect', MarketplaceAccount::class);

        return new AccountResource($service->disconnect($account)->load('warehouse'));
    }

    public function syncProducts(MarketplaceAccount $account, ListingService $listings): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);
        abort_unless($account->isActive(), 422, 'Shop chưa kết nối.');

        return response()->json(['data' => $listings->sync($account)]);
    }

    /** Re-computes and queues the published stock of every mapped listing of the shop. */
    public function pushStock(MarketplaceAccount $account, InventorySyncPlanner $planner): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);
        $variantIds = $account->listingVariants()->where('mapping_status', ListingVariant::MAPPED)->pluck('product_variant_id')->unique()->values()->all();

        return response()->json(['data' => ['queued' => $planner->plan($variantIds, force: true)]]);
    }

    /** Recent webhooks of this tenant's shops (inbox monitor). */
    public function webhooks(TenantContext $context): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);

        $events = WebhookEvent::query()->where('tenant_id', $context->id())->orderByDesc('id')->limit(50)
            ->get(['id', 'marketplace', 'event_type', 'external_order_id', 'status', 'result', 'attempts', 'received_at', 'processed_at']);

        return response()->json(['data' => $events->map(fn (WebhookEvent $e) => [
            'id' => $e->id,
            'marketplace' => $e->marketplace,
            'type' => $e->event_type,
            'order' => $e->external_order_id,
            'status' => $e->status,
            'result' => $e->result,
            'attempts' => $e->attempts,
            'received_at' => $e->received_at->toIso8601String(),
            'processed_at' => $e->processed_at?->toIso8601String(),
        ])]);
    }
}
