<?php

use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Models\Order;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
});

it('"Xoá kênh" deletes a channel without orders, hides one with orders and keeps its orders', function () {
    $shirt = makeVariant($this->tenant, 'AO-1');
    $empty = fakeShop($this->tenant, true, 'Shop chưa có đơn');
    expect(ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $empty->id)->count())->toBeGreaterThan(0);

    ($this->api)()->deleteJson("/api/v1/marketplace-accounts/{$empty->ulid}")->assertOk()->assertJsonPath('data.result', 'deleted');
    expect(MarketplaceAccount::withoutGlobalScopes()->whereKey($empty->id)->exists())->toBeFalse()
        ->and(ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $empty->id)->count())->toBe(0);

    // The plan's one shop is free again.
    $busy = fakeShop($this->tenant, true, 'Shop có đơn');
    $order = fakeOrder($busy, [[$shirt, 1]]);

    ($this->api)()->deleteJson("/api/v1/marketplace-accounts/{$busy->ulid}")->assertOk()->assertJsonPath('data.result', 'archived');
    $busy->refresh();
    expect($busy->status)->toBe(MarketplaceAccount::REVOKED)->and($busy->archived_at)->not->toBeNull()
        ->and(Order::withoutGlobalScopes()->whereKey($order->id)->exists())->toBeTrue();

    // Gone from the channel list; its order is still listed.
    ($this->api)()->getJson('/api/v1/marketplace-accounts')->assertOk()->assertJsonCount(0, 'data');
    ($this->api)()->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.id', $order->ulid);
});

it('only lets members who may connect channels remove one', function () {
    $shop = fakeShop($this->tenant, false);
    $viewer = addMember($this->tenant, 'viewer');
    ($this->api)($viewer)->deleteJson("/api/v1/marketplace-accounts/{$shop->ulid}")->assertForbidden();
    expect(MarketplaceAccount::withoutGlobalScopes()->whereKey($shop->id)->exists())->toBeTrue();
});
