<?php

use App\Modules\Tenancy\Support\TenantContext;

/** "Trên các kênh" on the product page: the shops a product is linked to, one row per shop. */
it('lists the shops a product is linked to, without other tenants', function () {
    [$tenant, $owner] = tenantWithOwner();
    $tee = makeVariant($tenant, 'AO-01', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    makeVariant($tenant, 'AO-02', ['product_name' => 'Áo polo', 'ref_price' => 200000]);
    $shop = fakeShop($tenant, name: 'Shop A');
    $product = TenantContext::run($tenant, fn () => $tee->product->ulid);

    $rows = actingInTenant($owner, $tenant)->getJson("/api/v1/products/{$product}/listings")->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['account']['shop_name'])->toBe('Shop A')
        ->and($rows[0]['account']['id'])->toBe($shop->ulid)
        ->and($rows[0]['linked'])->toBe(1)
        ->and($rows[0]['variants'])->toBe([$tee->ulid]);

    [$other, $otherOwner] = tenantWithOwner();
    actingInTenant($otherOwner, $other)->getJson("/api/v1/products/{$product}/listings")->assertNotFound();
});
