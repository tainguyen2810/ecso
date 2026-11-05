<?php

use App\Modules\Access\Models\Role;
use App\Modules\Marketplace\Models\MarketplaceAllocation;
use Illuminate\Support\Facades\DB;

/** Sync Center round 3: per-shop and per-SKU stock allocation, previewed before it is saved and pushed. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    $this->hat = makeVariant($this->tenant, 'MU-001', ['product_name' => 'Mũ', 'ref_price' => 90000]);
    stockIn($this->tenant, $this->shirt, 10);
    stockIn($this->tenant, $this->hat, 4);
    $this->shop = fakeShop($this->tenant);
    $this->remote = fn (string $sku) => (int) DB::table('fake_marketplace_products')->where('shop_id', $this->shop->external_shop_id)->where('seller_sku', $sku)->value('stock');
    $this->api = actingInTenant($this->owner, $this->tenant);
});

it('publishes everything when no allocation is set, as before', function () {
    expect(($this->remote)('AO-001'))->toBe(10)->and(($this->remote)('MU-001'))->toBe(4);
    $this->api->getJson('/api/v1/sync-center/allocations')->assertOk()
        ->assertJsonPath('data.shops.0.rule.mode', 'all')->assertJsonPath('data.shops.0.is_default', true)
        ->assertJsonPath('data.shops.0.mapped_skus', 2)->assertJsonPath('data.can_configure', true);
});

it('previews a shop default, then saves it and pushes the new numbers without touching internal stock', function () {
    $rule = ['account' => $this->shop->ulid, 'mode' => 'percent', 'value' => 50, 'buffer_qty' => 2];

    $this->api->postJson('/api/v1/sync-center/allocations/preview', $rule)->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.changed', 2)->assertJsonPath('data.to_zero', 0)
        ->assertJsonPath('data.rows.0.sku', 'AO-001')->assertJsonPath('data.rows.0.current', 10)->assertJsonPath('data.rows.0.next', 4);
    expect(($this->remote)('AO-001'))->toBe(10); // a preview changes nothing

    $this->api->putJson('/api/v1/sync-center/allocations', $rule)->assertOk()->assertJsonPath('data.queued', 2);

    // (10 − 2) × 50% = 4 and (4 − 2) × 50% = 1
    expect(($this->remote)('AO-001'))->toBe(4)->and(($this->remote)('MU-001'))->toBe(1)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->on_hand)->toBe(10)
        ->and(DB::table('audit_logs')->where('action', 'sync.allocation_changed')->exists())->toBeTrue();

    // New stock follows the rule: 10 + 6 = 16 → (16 − 2) × 50% = 7
    stockIn($this->tenant, $this->shirt, 6);
    expect(($this->remote)('AO-001'))->toBe(7);
});

it('lets one SKU override the shop default, and removing the override falls back to it', function () {
    $this->api->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'mode' => 'none'])->assertOk();
    expect(($this->remote)('AO-001'))->toBe(0)->and(($this->remote)('MU-001'))->toBe(0);

    $this->api->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'variant' => $this->shirt->ulid, 'mode' => 'fixed', 'value' => 3])->assertOk();
    expect(($this->remote)('AO-001'))->toBe(3)->and(($this->remote)('MU-001'))->toBe(0);

    $this->api->getJson('/api/v1/sync-center/allocations')->assertOk()
        ->assertJsonPath('data.shops.0.overrides', 1)
        ->assertJsonPath('data.overrides.0.variant.sku', 'AO-001')
        ->assertJsonPath("data.overrides.0.cells.{$this->shop->ulid}.published", 3)
        ->assertJsonPath("data.overrides.0.sellable.{$this->shop->ulid}", 10);

    // The shop default no longer governs the overridden SKU.
    $this->api->postJson('/api/v1/sync-center/allocations/preview', ['account' => $this->shop->ulid, 'mode' => 'all'])->assertOk()->assertJsonPath('data.total', 1);

    $this->api->postJson('/api/v1/sync-center/allocations/preview', ['account' => $this->shop->ulid, 'variant' => $this->shirt->ulid, 'reset' => true])->assertOk()
        ->assertJsonPath('data.rows.0.next', 0);
    $this->api->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'variant' => $this->shirt->ulid, 'reset' => true])->assertOk();
    expect(($this->remote)('AO-001'))->toBe(0)->and(MarketplaceAllocation::withoutGlobalScopes()->count())->toBe(1);
});

it('caps what a shop publishes and validates the rule', function () {
    $this->api->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'mode' => 'all', 'max_qty' => 6])->assertOk();
    expect(($this->remote)('AO-001'))->toBe(6)->and(($this->remote)('MU-001'))->toBe(4);

    $this->api->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'mode' => 'percent', 'value' => 150])->assertUnprocessable();
    $this->api->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'mode' => 'fixed'])->assertUnprocessable();
    $this->api->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'mode' => 'everything'])->assertUnprocessable();
});

it('lets a viewer look but not change, and keeps other businesses out', function () {
    $viewer = addMember($this->tenant, Role::VIEWER);
    actingInTenant($viewer, $this->tenant)->getJson('/api/v1/sync-center/allocations')->assertOk()->assertJsonPath('data.can_configure', false);
    actingInTenant($viewer, $this->tenant)->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'mode' => 'none'])->assertForbidden();

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->putJson('/api/v1/sync-center/allocations', ['account' => $this->shop->ulid, 'mode' => 'none'])->assertNotFound();
    actingInTenant($otherOwner, $other)->getJson('/api/v1/sync-center/allocations')->assertOk()->assertJsonCount(0, 'data.shops');
    expect(($this->remote)('AO-001'))->toBe(10);
});
