<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\InventorySyncPlanner;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** A5: the setup steps after a shop is connected for the first time (with the fake marketplace). */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->tee = makeVariant($this->tenant, 'AO-01', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    $this->polo = makeVariant($this->tenant, 'AO-02', ['product_name' => 'Áo polo', 'ref_price' => 200000]);
    stockIn($this->tenant, $this->polo, 5); // EzSell already has stock of this one
});

function setupShop(): MarketplaceAccount
{
    $made = test()->api->postJson('/api/v1/marketplace-accounts/fake', ['shop_name' => 'Shop thật', 'setup' => true])->assertCreated()
        ->assertJsonPath('data.setup_pending', true);

    return MarketplaceAccount::withoutGlobalScopes()->where('ulid', $made->json('data.id'))->sole();
}

it('pushes no stock while the setup is pending', function () {
    $shop = setupShop();
    TenantContext::run($this->tenant, fn () => app(ListingService::class)->confirmSuggestions($shop));

    expect(TenantContext::run($this->tenant, fn () => app(InventorySyncPlanner::class)->plan([$this->tee->id, $this->polo->id], force: true)))->toBe(0)
        ->and(InventorySyncOutbox::withoutGlobalScopes()->where('marketplace_account_id', $shop->id)->exists())->toBeFalse();
});

it('links by seller SKU, creates the products the warehouse lacks, takes the shop stock as opening stock and then syncs', function () {
    $shop = setupShop();
    // The shop sells a product EzSell does not have, and has its own stock.
    app(FakeMarketplace::class)->putProducts($shop->external_shop_id, [
        ['product_id' => 'FPN1', 'sku_id' => 'FSN1', 'seller_sku' => 'TOTE-KEM', 'title' => 'Túi tote', 'variant_name' => 'Kem', 'price' => 99000],
    ]);
    DB::table('fake_marketplace_products')->where('shop_id', $shop->external_shop_id)->where('seller_sku', 'AO-01')->update(['stock' => 24]);
    DB::table('fake_marketplace_products')->where('shop_id', $shop->external_shop_id)->where('seller_sku', 'AO-02')->update(['stock' => 9]);
    DB::table('fake_marketplace_products')->where('shop_id', $shop->external_shop_id)->where('seller_sku', 'TOTE-KEM')->update(['stock' => 7]);
    $this->api->postJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup/pull")->assertStatus(202);

    $overview = $this->api->getJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup")->assertOk()->json('data');
    expect($overview['pending'])->toBeTrue()->and($overview['listings_synced'])->toBeTrue()
        ->and($overview['counts'])->toMatchArray(['total' => 4, 'matched' => 2, 'create' => 1, 'create_without_sku' => 1, 'later' => 0]);

    // Step 2 without the listing that has no seller SKU.
    $this->api->postJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup/link", ['without_sku' => false])->assertOk()
        ->assertJsonPath('data.linked', 2)->assertJsonPath('data.created', 1);
    $tote = ProductVariant::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('sku', 'TOTE-KEM')->sole();
    expect(ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $shop->id)->where('mapping_status', ListingVariant::MAPPED)->count())->toBe(3)
        ->and(ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $shop->id)->whereNull('seller_sku')->value('mapping_status'))->toBe(ListingVariant::UNMAPPED);

    // Step 3: EzSell has stock of AO-02, so the suggestion is to keep EzSell's stock; the user takes the shop's anyway.
    $stock = $this->api->getJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup")->json('data.stock');
    expect($stock['suggested'])->toBe('ezsell')->and($stock['from_shop_lines'])->toBe(2)->and($stock['from_shop_quantity'])->toBe(31);
    $this->api->postJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup/stock", ['source' => 'marketplace', 'cost_percent' => 60])->assertOk()
        ->assertJsonPath('data.lines', 2)->assertJsonPath('data.quantity', 31);
    $warehouse = defaultWarehouse($this->tenant);
    expect(balanceOf($warehouse, $this->tee)->on_hand)->toBe(24)
        ->and(balanceOf($warehouse, $tote)->on_hand)->toBe(7)
        ->and(balanceOf($warehouse, $this->polo)->on_hand)->toBe(5) // had stock: not counted twice
        ->and(StockReceipt::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source', StockReceipt::SOURCE_OPENING)->count())->toBe(1)
        ->and(DB::table('stock_receipt_lines')->where('product_variant_id', $this->tee->id)->value('unit_cost'))->toBe('90000.0000');

    // Step 4: sync goes on and every linked SKU is published.
    $this->api->postJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup/finish", ['settings' => ['sync_orders' => true, 'sync_stock' => true, 'import_new_products' => false]])->assertOk()
        ->assertJsonPath('data.setup_pending', false)->assertJsonPath('data.settings.import_new_products', false);
    expect(InventorySyncOutbox::withoutGlobalScopes()->where('marketplace_account_id', $shop->id)->count())->toBe(3);
});

it('leaves stock sync off when the user chooses so', function () {
    $shop = setupShop();
    $this->api->postJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup/link")->assertOk();
    $this->api->postJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup/stock", ['source' => 'none'])->assertOk()->assertJsonPath('data.receipt', null);
    $this->api->postJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup/finish")->assertOk()
        ->assertJsonPath('data.settings.sync_stock', false)->assertJsonPath('data.setup_pending', false);
    expect(InventorySyncOutbox::withoutGlobalScopes()->where('marketplace_account_id', $shop->id)->exists())->toBeFalse();
});

it('keeps shops connected the old way out of the setup steps', function () {
    $shop = fakeShop($this->tenant);
    expect($shop->setupPending())->toBeFalse();
    $this->api->getJson("/api/v1/marketplace-accounts/{$shop->ulid}/setup")->assertOk()->assertJsonPath('data.pending', false);
});
