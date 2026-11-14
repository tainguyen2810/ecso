<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Jobs\SyncListingsJob;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FakeMarketplace $fake,
        private readonly ListingService $listings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Connects a shop on the FAKE marketplace and fills it with listings copied from the tenant's catalog
     * (a few deliberately different, to exercise mapping). Real marketplaces connect through OAuth (Phase 4+).
     */
    public function connectFake(string $shopName, ?Warehouse $warehouse, bool $setup = false): MarketplaceAccount
    {
        if (! ConnectorRegistry::fakeEnabled()) {
            throw ValidationException::withMessages(['marketplace' => 'Sàn giả lập đang tắt.']);
        }

        $account = DB::transaction(function () use ($shopName, $warehouse, $setup) {
            DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');
            $this->context->entitlements()->assertWithin('limit.shops', MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)->count());

            $account = MarketplaceAccount::query()->create([
                'marketplace' => FakeConnector::MARKETPLACE,
                'external_shop_id' => 'fake-'.Str::lower(Str::random(10)),
                'shop_name' => $shopName,
                'region' => 'VN',
                'status' => MarketplaceAccount::CONNECTED,
                'warehouse_id' => $warehouse->id ?? Warehouse::query()->where('is_default', true)->value('id'),
                'connected_at' => now(),
            ]);
            if ($setup) {
                // A5: tried like a real shop, through the setup steps.
                $account->forceFill(['settings' => ['setup' => 'pending']])->save();
            }
            $this->audit->record('marketplace.connected', $account, ['marketplace' => $account->marketplace, 'shop' => $shopName]);

            return $account;
        });

        $this->fake->putProducts($account->external_shop_id, $this->fakeCatalog($account));
        $this->listings->sync($account);

        return $account;
    }

    /**
     * @param  array{shop_name?: string, warehouse_id?: int|null, sync_enabled?: bool, settings?: array<string, bool>}  $data
     */
    public function update(MarketplaceAccount $account, array $data): MarketplaceAccount
    {
        $pricesWereOn = $account->syncs('sync_prices');
        $ordersWereOn = $account->syncs('sync_orders');
        $stockWasOn = $account->sync_enabled && $account->syncs('sync_stock');
        $changedSettings = [];
        if (isset($data['settings'])) {
            $changedSettings = array_intersect_key($data['settings'], MarketplaceAccount::SYNC_DEFAULTS + ['order_sync_interval' => 15]);
            $data['settings'] = array_merge((array) $account->settings, $changedSettings);
            $account->forceFill(['settings' => $data['settings']]);
            unset($data['settings']);
        }
        // Turning order sync back on starts from now: orders created while it was off stay on the store.
        if (! $ordersWereOn && $account->syncs('sync_orders') && $account->marketplace === WooCommerceConnector::MARKETPLACE) {
            $account->orders_since = now();
        }
        $account->fill($data)->save();
        $this->audit->record('marketplace.updated', $account, $data + ($changedSettings !== [] ? ['settings' => $changedSettings] : []));

        // Stock sync turned back on: publish the current stock of every linked SKU (nothing was pushed while off).
        if (! $stockWasOn && $account->sync_enabled && $account->syncs('sync_stock') && $account->isActive()) {
            $variantIds = $account->listingVariants()->where('mapping_status', ListingVariant::MAPPED)->pluck('product_variant_id')->unique()->values()->all();
            if ($variantIds !== []) {
                app(InventorySyncPlanner::class)->plan($variantIds, force: true);
            }
        }

        // Turning price sync on re-reads the store: each linked SKU then follows its price source.
        if (! $pricesWereOn && $account->syncs('sync_prices') && $account->isActive()) {
            SyncListingsJob::dispatch($account->id)->afterCommit();
        }

        return $account;
    }

    /** Stops syncing and forgets the tokens. Orders and history are kept. */
    public function disconnect(MarketplaceAccount $account): MarketplaceAccount
    {
        if ($account->marketplace === WooCommerceConnector::MARKETPLACE) {
            // Stop the store from sending webhooks to us (best effort, before the keys are forgotten).
            app(WooCommerceConnectService::class)->removeWebhooks($account);
        }

        DB::transaction(function () use ($account) {
            $account->credential()->delete();
            $account->forceFill(['status' => MarketplaceAccount::REVOKED, 'sync_enabled' => false])->save();
            $this->audit->record('marketplace.disconnected', $account, ['shop' => $account->shop_name]);
        });

        return $account;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fakeCatalog(MarketplaceAccount $account): array
    {
        $variants = ProductVariant::query()->with('product')->where('status', 'active')->orderBy('id')->limit(40)->get();
        $skus = [];
        foreach ($variants as $i => $variant) {
            $skus[] = [
                'product_id' => 'FP'.$variant->product_id,
                'sku_id' => 'FS'.$variant->id.Str::lower(Str::random(4)),
                // Every 7th listing uses a seller SKU that does not match exactly: it must not be auto-suggested.
                'seller_sku' => $i % 7 === 6 ? $variant->sku.'-CU' : $variant->sku,
                'title' => $variant->product->name,
                'variant_name' => $variant->name,
                'price' => max(10000, (float) $variant->ref_price),
            ];
        }
        $skus[] = ['product_id' => 'FPX1', 'sku_id' => 'FSX1', 'seller_sku' => null, 'title' => 'Quà tặng kèm (không có SKU)', 'variant_name' => null, 'price' => 0];

        return $skus;
    }
}
