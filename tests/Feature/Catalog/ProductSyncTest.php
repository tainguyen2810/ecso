<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\PriceChange;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeWooStore;

/** Audit round B: a stopped SKU is not sold anywhere, imports and website products follow the same rules as edits. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->mouse = makeVariant($this->tenant, 'G304-BK', ['product_name' => 'Chuột G304', 'ref_price' => 499000]);
    stockIn($this->tenant, $this->mouse, 12);
    $this->store = new FakeWooStore;
    $this->store->addSimple(11, 'G304-BK', 'Chuột Logitech G304', '549000', '499000', stock: 40);
    config(['app.url' => 'https://erp.example.vn']);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->productId = TenantContext::run($this->tenant, fn () => $this->mouse->product->ulid);
});

it('publishes 0 on the website while a product is stopped and the real stock again when it is back on sale', function () {
    $account = wooConnect($this);
    wooMap($this, $account);
    expect($this->store->products[11]['stock_quantity'])->toBe(12);

    $this->api->postJson('/api/v1/products/bulk-status', ['ids' => [$this->productId], 'status' => 'inactive'])->assertOk();
    expect($this->store->products[11]['stock_quantity'])->toBe(0);

    $this->api->postJson('/api/v1/products/bulk-status', ['ids' => [$this->productId], 'status' => 'active'])->assertOk();
    expect($this->store->products[11]['stock_quantity'])->toBe(12);

    // A variant stopped in the product form works the same way.
    $variantId = $this->mouse->ulid;
    $this->api->putJson("/api/v1/products/{$this->productId}", ['variants' => [['id' => $variantId, 'sku' => 'G304-BK', 'status' => 'inactive']]])->assertOk();
    expect($this->store->products[11]['stock_quantity'])->toBe(0);
});

it('refuses a stopped SKU in a new order but keeps it on an order that already had it', function () {
    $channel = defaultChannel($this->tenant)->ulid;
    $line = ['variant_id' => $this->mouse->ulid, 'quantity' => 1];
    $orderId = $this->api->postJson('/api/v1/sales-orders', ['channel_id' => $channel, 'lines' => [$line]])->assertCreated()->json('data.id');

    $this->api->postJson('/api/v1/products/bulk-status', ['ids' => [$this->productId], 'status' => 'archived'])->assertOk();

    $this->api->postJson('/api/v1/sales-orders', ['channel_id' => $channel, 'lines' => [$line]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.variant_id' => 'đang ngừng bán']);
    $this->api->putJson("/api/v1/sales-orders/{$orderId}", ['channel_id' => $channel, 'lines' => [['variant_id' => $this->mouse->ulid, 'quantity' => 2]]])->assertOk();

    // The order and POS pickers only offer SKUs on sale.
    expect($this->api->getJson('/api/v1/variants?q=G304&sellable=1')->json('data'))->toBe([])
        ->and($this->api->getJson('/api/v1/variants?q=G304&stocked=1')->json('data'))->toBe([]); // archived hides it everywhere
});

it('sends a price changed by an Excel import to the website and the price history', function () {
    $account = wooConnect($this);
    wooMap($this, $account);
    $this->api->patchJson("/api/v1/marketplace-accounts/{$account->ulid}", ['settings' => ['sync_prices' => true]])->assertOk();

    $this->api->postJson('/api/v1/products/import', ['file' => UploadedFile::fake()->createWithContent('gia.csv', "product_name,sku,ref_price\nChuột G304,g304-bk,530000")])
        ->assertOk()->assertJsonPath('updated', 1);

    expect($this->store->products[11]['regular_price'])->toBe('530000')
        ->and(PriceChange::withoutGlobalScopes()->where('product_variant_id', $this->mouse->id)->where('new_price', '530000')->exists())->toBeTrue();
});

it('lets a product whose SKU came from the website be edited, while new SKUs keep the format', function () {
    $odd = makeVariant($this->tenant, 'AO THUN #01', ['product_name' => 'Áo thun web']);
    $product = TenantContext::run($this->tenant, fn () => $odd->product->ulid);

    $this->api->putJson("/api/v1/products/{$product}", ['variants' => [['id' => $odd->ulid, 'sku' => 'AO THUN #01', 'ref_price' => 120000]]])->assertOk();
    $this->api->putJson("/api/v1/products/{$product}", ['variants' => [['id' => $odd->ulid, 'sku' => 'AO THUN #02']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku' => 'SKU chỉ gồm']);
    $this->api->postJson('/api/v1/products', ['name' => 'Mới', 'variants' => [['sku' => 'CO DAU CACH']]])->assertUnprocessable();
});

it('approves website products safely: SKU case, a SKU in the recycle bin, and the website details', function () {
    $this->store->addVariable(20, 'Áo thun', [[21, 'ao-den', 'Đen', '150000'], [22, 'AO-TRANG', 'Trắng', '150000']]);
    $this->store->products[20]['description'] = '<p>Áo <b>cotton</b> 100%</p><p>Form rộng</p>';
    $this->store->products[20]['categories'] = [['id' => 5, 'name' => 'Áo &amp; quần']];
    $this->store->products[20]['weight'] = '0.25';
    $this->store->variations[20][22]['global_unique_id'] = '8930000000222';

    $account = wooConnect($this);
    // Before approval, ECSO gets AO-DEN (other case) and has AO-TRANG in the recycle bin.
    $black = makeVariant($this->tenant, 'AO-DEN', ['product_name' => 'Áo đen ECSO']);
    $white = makeVariant($this->tenant, 'AO-TRANG', ['product_name' => 'Áo trắng cũ']);
    TenantContext::run($this->tenant, fn () => $white->product->delete() && $white->delete());

    $this->api->postJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products/approve", ['all' => true])->assertOk()
        ->assertJsonPath('data.created', 0)->assertJsonPath('data.linked', 1);

    $links = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->whereIn('external_sku_id', ['21', '22'])->pluck('product_variant_id', 'external_sku_id');
    expect($links['21'])->toBe($black->id)->and($links['22'])->toBe($white->id)
        ->and(ProductVariant::withoutGlobalScopes()->whereKey($white->id)->value('deleted_at'))->toBeNull();

    // A new website product is created with its description, category, weight, options and barcode.
    $this->store->addVariable(30, 'Quần', [[31, 'QUAN-S', 'S', '200000'], [32, 'QUAN-M', 'M', '200000']]);
    $this->store->products[30] += ['description' => '<p>Quần <i>kaki</i></p>', 'categories' => [['id' => 6, 'name' => 'Quần']], 'weight' => '0.4'];
    $this->store->variations[30][32]['global_unique_id'] = '8930000000333';
    wooWebhook($this, 'product.created', $this->store->products[30])->assertOk();
    $this->api->postJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products/approve", ['all' => true])->assertOk()->assertJsonPath('data.created', 1);

    $m = TenantContext::run($this->tenant, fn () => ProductVariant::query()->where('sku', 'QUAN-M')->with('product')->sole());
    expect($m->product->description)->toBe('Quần kaki')
        ->and(TenantContext::run($this->tenant, fn () => Category::query()->find($m->product->category_id)?->name))->toBe('Quần')
        ->and($m->weight_gram)->toBe(400)
        ->and($m->options)->toBe(['Màu' => 'M'])
        ->and($m->barcode)->toBe('8930000000333');
});
