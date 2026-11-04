<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\PriceChange;
use App\Modules\Tenancy\Support\TenantContext;
use Tests\Support\FakeWooStore;

/*
 * Round 2: two-way price with a WooCommerce store, against the simulated store only (tests/Support/FakeWooStore.php).
 */

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->mouse = makeVariant($this->tenant, 'G304-BK', ['product_name' => 'Chuột G304', 'ref_price' => 499000]);
    stockIn($this->tenant, $this->mouse, 12);
    $this->store = new FakeWooStore;
    $this->store->addSimple(11, 'G304-BK', 'Chuột Logitech G304', '549000', '499000', stock: 40);
    config(['app.url' => 'https://erp.example.vn']);
    $this->account = wooConnect($this);
    wooMap($this, $this->account);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->productId = TenantContext::run($this->tenant, fn () => $this->mouse->product->ulid);
});

function wooPriceOn($test): void
{
    $test->api->patchJson("/api/v1/marketplace-accounts/{$test->account->ulid}", ['settings' => ['sync_prices' => true]])->assertOk();
}

function mousePrice($test): string
{
    return (string) ProductVariant::withoutGlobalScopes()->whereKey($test->mouse->id)->value('ref_price');
}

function priceHistory($test): array
{
    return PriceChange::withoutGlobalScopes()->where('product_variant_id', $test->mouse->id)->orderBy('id')
        ->get(['source', 'status', 'old_price', 'new_price', 'reason'])->toArray();
}

it('changes nothing on the website while price sync is off', function () {
    TenantContext::run($this->tenant, fn () => $this->mouse->fresh()->update(['ref_price' => 520000]));

    expect($this->store->products[11]['regular_price'])->toBe('549000')
        ->and(priceHistory($this))->toHaveCount(1); // the ECSO change itself is still in the history
});

it('pushes ECSO prices while ECSO holds the price, and undoes a change made on the website', function () {
    wooPriceOn($this); // in stock + auto = ECSO holds: the website's regular price becomes ECSO's
    expect($this->store->products[11]['regular_price'])->toBe('499000')
        ->and(ListingVariant::withoutGlobalScopes()->where('external_sku_id', '11')->value('price_push_status'))->toBe('ok');

    TenantContext::run($this->tenant, fn () => $this->mouse->fresh()->update(['ref_price' => 520000]));
    expect($this->store->products[11]['regular_price'])->toBe('520000')
        ->and(priceHistory($this))->toHaveCount(1)
        ->and(priceHistory($this)[0])->toMatchArray(['source' => 'ecso', 'status' => 'applied']);

    // Someone edits the price in wp-admin: Woo sends product.updated, ECSO logs it and puts its own price back.
    $this->store->products[11]['regular_price'] = '450000';
    wooWebhook($this, 'product.updated', $this->store->products[11] + ['date_modified_gmt' => 'b'])->assertOk();

    expect($this->store->products[11]['regular_price'])->toBe('520000')
        ->and(mousePrice($this))->toBe('520000.00')
        ->and(priceHistory($this)[1])->toMatchArray(['source' => 'channel', 'status' => 'reverted', 'new_price' => '450000.00']);

    // The echo of our own push changes nothing.
    wooWebhook($this, 'product.updated', $this->store->products[11] + ['date_modified_gmt' => 'c'])->assertOk();
    expect(priceHistory($this))->toHaveCount(2);
});

it('takes the website price (sale price first) when the website holds it, without pushing back', function () {
    wooPriceOn($this);
    $this->api->putJson("/api/v1/products/{$this->productId}/price-source", ['price_source' => 'woo'])->assertOk();
    $this->api->getJson("/api/v1/products/{$this->productId}/prices")->assertOk()
        ->assertJsonPath('data.variants.0.price_source', 'woo')->assertJsonPath('data.variants.0.holder', 'channel')
        ->assertJsonPath('data.variants.0.channels.0.shop_name', $this->account->shop_name);

    $this->store->products[11] = array_merge($this->store->products[11], ['regular_price' => '549000', 'sale_price' => '459000', 'price' => '459000']);
    wooWebhook($this, 'product.updated', $this->store->products[11] + ['date_modified_gmt' => 'd'])->assertOk();

    expect(mousePrice($this))->toBe('459000.00')
        ->and($this->store->products[11]['regular_price'])->toBe('549000')
        ->and(collect(priceHistory($this))->last())->toMatchArray(['source' => 'channel', 'status' => 'applied'])
        ->and(collect(priceHistory($this))->last()['reason'])->toContain('khuyến mãi');
});

it('holds a website price of 0 or more than 50% away for approval', function () {
    wooPriceOn($this);
    $this->api->putJson("/api/v1/products/{$this->productId}/price-source", ['price_source' => 'woo'])->assertOk();
    $before = mousePrice($this);

    $this->store->products[11] = array_merge($this->store->products[11], ['sale_price' => '99000', 'price' => '99000']);
    wooWebhook($this, 'product.updated', $this->store->products[11] + ['date_modified_gmt' => 'e'])->assertOk();
    expect(mousePrice($this))->toBe($before);

    $pending = $this->api->getJson("/api/v1/marketplace-accounts/{$this->account->ulid}/price-changes")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Lệch quá 50%')->assertJsonPath('data.0.on_sale', true)->json('data.0');
    $this->api->getJson("/api/v1/marketplace-accounts/{$this->account->ulid}")->assertJsonPath('overview.pending_prices', 1);

    $this->api->postJson("/api/v1/price-changes/{$pending['id']}/approve")->assertOk()->assertJsonPath('data.status', 'applied');
    expect(mousePrice($this))->toBe('99000.00');

    // A price of 0, refused by the owner, is not asked again for the same price.
    $this->store->products[11] = array_merge($this->store->products[11], ['sale_price' => '', 'regular_price' => '0', 'price' => '0']);
    wooWebhook($this, 'product.updated', $this->store->products[11] + ['date_modified_gmt' => 'f'])->assertOk();
    $zero = $this->api->getJson("/api/v1/marketplace-accounts/{$this->account->ulid}/price-changes")->assertJsonPath('data.0.reason', 'Giá bằng 0')->json('data.0.id');
    $this->api->postJson("/api/v1/price-changes/{$zero}/reject")->assertOk();
    wooWebhook($this, 'product.updated', $this->store->products[11] + ['date_modified_gmt' => 'g'])->assertOk();

    $this->api->getJson("/api/v1/marketplace-accounts/{$this->account->ulid}/price-changes")->assertJsonCount(0, 'data');
    expect(mousePrice($this))->toBe('99000.00');
});

it('lets auto hand the price to the website once ECSO is out of stock', function () {
    wooPriceOn($this);
    $this->api->getJson("/api/v1/products/{$this->productId}/prices")->assertJsonPath('data.variants.0.holder', 'ecso');

    TenantContext::run($this->tenant, fn () => app(InventoryService::class)->issue(
        defaultWarehouse($this->tenant), [['variant_id' => $this->mouse->id, 'quantity' => 12]], 'damage-all', null, StockMovement::DAMAGE_OUT,
    ));
    $this->api->getJson("/api/v1/products/{$this->productId}/prices")->assertJsonPath('data.variants.0.holder', 'channel');

    $this->store->products[11] = array_merge($this->store->products[11], ['sale_price' => '479000', 'price' => '479000']);
    wooWebhook($this, 'product.updated', $this->store->products[11] + ['date_modified_gmt' => 'h'])->assertOk();
    expect(mousePrice($this))->toBe('479000.00')
        ->and(collect(priceHistory($this))->last()['reason'])->toStartWith('Hết hàng');
});
