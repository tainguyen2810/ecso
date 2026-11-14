<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Tiki\TikiConnector;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Http\Resources\AccountResource;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PriceChange;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\AccountService;
use App\Modules\Marketplace\Services\InventorySyncPlanner;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Marketplace\Services\NewListingService;
use App\Modules\Marketplace\Services\ShopAuthorizationService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

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
                'credentials_configured' => in_array($key, ['fake', WooCommerceConnector::MARKETPLACE, TikiConnector::MARKETPLACE], true) || ShopAuthorizationService::configured($key),
                'is_fake' => $key === 'fake',
                // How a shop is connected: marketplace app authorization, store approval / API keys, or the simulator.
                'connect_mode' => match ($key) {
                    'fake' => 'fake',
                    WooCommerceConnector::MARKETPLACE => 'store',
                    // The shop's own In-house app: Client ID and Secret pasted by the owner.
                    TikiConnector::MARKETPLACE => 'app_keys',
                    default => 'oauth',
                },
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

    /** One shop with what its settings page shows (WooCommerce store page). */
    public function show(MarketplaceAccount $account, NewListingService $newListings): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $account = MarketplaceAccount::query()->whereKey($account->id)->with('warehouse')->withCount([
            'listingVariants as listings_total',
            'listingVariants as listings_mapped' => fn ($q) => $q->where('mapping_status', ListingVariant::MAPPED),
            'listingVariants as listings_suggested' => fn ($q) => $q->where('mapping_status', ListingVariant::SUGGESTED),
            'listingVariants as push_errors' => fn ($q) => $q->whereIn('push_status', ['rejected', 'failed', 'retrying']),
        ])->addSelect(['pending_push' => InventorySyncOutbox::query()->selectRaw('count(*)')
            ->whereColumn('marketplace_account_id', 'marketplace_accounts.id')->where('status', InventorySyncOutbox::PENDING)])->firstOrFail();

        $failedWebhooks = WebhookEvent::query()->where('marketplace_account_id', $account->id)
            ->where('status', WebhookEvent::FAILED)->where('received_at', '>=', now()->subDays(7))->count();

        return (new AccountResource($account))->additional(['overview' => [
            'orders_this_week' => Order::query()->where('marketplace_account_id', $account->id)->where('placed_at', '>=', now()->startOfWeek())->count(),
            'new_products' => $newListings->count($account),
            'pending_prices' => PriceChange::query()->where('marketplace_account_id', $account->id)->where('status', PriceChange::PENDING)->count(),
            'sync_errors' => (int) $account->push_errors + $failedWebhooks
                + SyncError::query()->where('marketplace_account_id', $account->id)->whereNull('resolved_at')->count(),
        ]])->response();
    }

    /** Store products not in ECSO yet, waiting for approval. */
    public function newProducts(MarketplaceAccount $account, NewListingService $newListings): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);

        return response()->json(['data' => $newListings->pending($account)->map(fn ($l) => [
            'id' => $l->ulid,
            'title' => $l->title,
            'image_url' => $l->image_url,
            'variants' => $l->variants->map(fn (ListingVariant $v) => [
                'name' => $v->name,
                'seller_sku' => $v->seller_sku,
                'price' => $v->price,
                'regular_price' => $v->regular_price,
                'sale_price' => $v->sale_price,
            ])->values(),
        ])->values()]);
    }

    public function approveNewProducts(Request $request, MarketplaceAccount $account, NewListingService $newListings): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);
        $data = $request->validate(['listing_ids' => ['nullable', 'array', 'max:500'], 'listing_ids.*' => ['string'], 'all' => ['boolean']]);
        abort_if(empty($data['all']) && empty($data['listing_ids']), 422, 'Chọn sản phẩm cần duyệt.');

        return response()->json(['data' => $newListings->approve($account, ! empty($data['all']) ? null : $data['listing_ids'])]);
    }

    public function skipNewProducts(Request $request, MarketplaceAccount $account, NewListingService $newListings): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);
        $data = $request->validate(['listing_ids' => ['required', 'array', 'max:500'], 'listing_ids.*' => ['string']]);

        return response()->json(['data' => ['skipped' => $newListings->skip($account, $data['listing_ids'])]]);
    }

    public function connectFake(Request $request, AccountService $service): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'string'],
            'setup' => ['sometimes', 'boolean'],
        ]);
        $warehouse = isset($data['warehouse_id']) ? Warehouse::query()->where('ulid', $data['warehouse_id'])->where('purpose', Warehouse::PURPOSE_SALE)->firstOrFail() : null;

        $account = $service->connectFake($data['shop_name'], $warehouse, (bool) ($data['setup'] ?? false));

        return (new AccountResource($account->load('warehouse')))->response()->setStatusCode(201);
    }

    /** Starts the seller authorization of a real marketplace shop: returns the marketplace URL to open. */
    public function authorizeShop(Request $request, string $marketplace, ShopAuthorizationService $auth): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);

        return response()->json(['data' => ['url' => $auth->start($marketplace, $request->user()->id)['url']]]);
    }

    /** Called by the SPA callback page with the parameters the marketplace appended to the redirect. */
    public function completeAuthorization(Request $request, string $marketplace, ShopAuthorizationService $auth): JsonResponse
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $query = $request->validate(['state' => ['required', 'string', 'max:100'], 'params' => ['array', 'max:20'], 'params.*' => ['nullable', 'string', 'max:2000']]);
        $account = $auth->complete($marketplace, ['state' => $query['state']] + ($query['params'] ?? []), $request->user()->id);

        return (new AccountResource($account->load('warehouse')))->response()->setStatusCode(201);
    }

    public function update(Request $request, MarketplaceAccount $account, AccountService $service): AccountResource
    {
        $this->authorize('connect', MarketplaceAccount::class);
        $data = $request->validate([
            'shop_name' => ['sometimes', 'string', 'max:100'],
            'warehouse_id' => ['sometimes', 'string'],
            'sync_enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            ...collect(MarketplaceAccount::SETTING_KEYS)->mapWithKeys(fn ($k) => ["settings.{$k}" => ['sometimes', 'boolean']])->all(),
            'settings.order_sync_interval' => ['sometimes', 'integer', Rule::in(MarketplaceAccount::ORDER_SYNC_INTERVALS)],
        ]);
        if (isset($data['warehouse_id'])) {
            $data['warehouse_id'] = Warehouse::query()->where('ulid', $data['warehouse_id'])->where('status', 'active')->where('purpose', Warehouse::PURPOSE_SALE)->firstOrFail()->id;
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
