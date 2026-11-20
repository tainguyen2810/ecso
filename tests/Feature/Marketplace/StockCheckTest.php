<?php

use App\Modules\Analytics\Console\MorningBriefCommand;
use App\Modules\Inventory\Services\LowStockQuery;
use App\Modules\Marketplace\Models\StockCheck;
use App\Modules\Marketplace\Services\StockReconciler;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Đợt 16 "Đối soát tồn với sàn mỗi đêm": stock on the marketplace compared with ECSO's, listed and set right. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
    $this->hat = makeVariant($this->tenant, 'NON-01', ['product_name' => 'Nón']);
    stockIn($this->tenant, $this->shirt, 10);
    stockIn($this->tenant, $this->hat, 4);
    $this->shop = fakeShop($this->tenant);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->remote = fn (string $sku) => (int) DB::table('fake_marketplace_products')->where('shop_id', $this->shop->external_shop_id)->where('seller_sku', $sku)->value('stock');
    $this->api->postJson("/api/v1/marketplace-accounts/{$this->shop->ulid}/push-stock")->assertOk();
});

it('finds SKUs whose marketplace stock was changed, lists them and pushes the right figure back', function () {
    expect(($this->remote)('AO-001'))->toBe(10);
    // Someone typed 25 on the marketplace: the shop could sell 15 shirts it does not have.
    DB::table('fake_marketplace_products')->where('shop_id', $this->shop->external_shop_id)->where('seller_sku', 'AO-001')->update(['stock' => 25]);

    $this->artisan('ecso:marketplace:stock-check')->assertSuccessful();

    $check = StockCheck::withoutGlobalScopes()->sole();
    expect($check->status)->toBe(StockCheck::DONE)->and($check->checked)->toBe(2)->and($check->mismatched)->toBe(1)
        ->and($check->items)->toBe([['sku' => 'AO-001', 'name' => 'Áo thun', 'marketplace_qty' => 25, 'ecso_qty' => 10]])
        ->and(($this->remote)('AO-001'))->toBe(10);

    $this->api->getJson("/api/v1/marketplace-accounts/{$this->shop->ulid}/stock-check")->assertOk()
        ->assertJsonPath('data.mismatched', 1)->assertJsonPath('data.items.0.marketplace_qty', 25);
    $shop = collect($this->api->getJson('/api/v1/sync-center/overview')->assertOk()->json('data.shops'))->firstWhere('id', $this->shop->ulid);
    expect($shop['stock_check']['mismatched'])->toBe(1);

    // The 8 am brief tells it.
    $f = TenantContext::run($this->tenant, fn () => MorningBriefCommand::figures($this->tenant, app(LowStockQuery::class)));
    expect($f['stock_fixed'])->toBe(1)->and(MorningBriefCommand::message($f)['body'])->toContain('Đêm qua sửa 1 SKU lệch tồn trên sàn.');

    // A second run finds everything right; "Đối soát ngay" runs it on demand.
    $this->api->postJson("/api/v1/marketplace-accounts/{$this->shop->ulid}/stock-check")->assertStatus(202);
    expect(StockCheck::withoutGlobalScopes()->latest('id')->first())->toMatchArray(['trigger' => 'manual', 'mismatched' => 0, 'checked' => 2]);
});

it('skips shops with stock sync off', function () {
    TenantContext::run($this->tenant, fn () => $this->shop->forceFill(['settings' => ['sync_stock' => false] + (array) $this->shop->settings])->save());
    expect(StockReconciler::eligible($this->shop->fresh()))->toBeFalse();
    $this->artisan('ecso:marketplace:stock-check')->assertSuccessful();
    expect(StockCheck::withoutGlobalScopes()->count())->toBe(0);
    $this->api->postJson("/api/v1/marketplace-accounts/{$this->shop->ulid}/stock-check")->assertStatus(422);
});
