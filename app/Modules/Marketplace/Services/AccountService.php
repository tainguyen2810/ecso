<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
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
    public function connectFake(string $shopName, ?Warehouse $warehouse): MarketplaceAccount
    {
        if (! ConnectorRegistry::fakeEnabled()) {
            throw ValidationException::withMessages(['marketplace' => 'Sàn giả lập đang tắt.']);
        }

        $account = DB::transaction(function () use ($shopName, $warehouse) {
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
            $this->audit->record('marketplace.connected', $account, ['marketplace' => $account->marketplace, 'shop' => $shopName]);

            return $account;
        });

        $this->fake->putProducts($account->external_shop_id, $this->fakeCatalog($account));
        $this->listings->sync($account);

        return $account;
    }

    /**
     * @param  array{shop_name?: string, warehouse_id?: int|null, sync_enabled?: bool}  $data
     */
    public function update(MarketplaceAccount $account, array $data): MarketplaceAccount
    {
        $account->fill($data)->save();
        $this->audit->record('marketplace.updated', $account, $data);

        return $account;
    }

    /** Stops syncing and forgets the tokens. Orders and history are kept. */
    public function disconnect(MarketplaceAccount $account): MarketplaceAccount
    {
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
