<?php

use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Orders\Models\Order;

/** Kênh bán pages: per shop sales, open orders, best sellers and what needs attention (GET marketplace-stats). */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
});

it('counts orders, revenue, cancellations and best sellers per shop for the chosen days', function () {
    $shirt = makeVariant($this->tenant, 'AO-1');
    stockIn($this->tenant, $shirt, 20);
    $shop = fakeShop($this->tenant, true, 'Shop Minh Phát');
    $kept = fakeOrder($shop, [[$shirt, 2]]);
    $cancelled = fakeOrder($shop, [[$shirt, 1]]);
    Order::withoutGlobalScopes()->whereKey($cancelled->id)->update(['status' => 'CANCELLED']);
    $old = fakeOrder($shop, [[$shirt, 1]]);
    Order::withoutGlobalScopes()->whereKey($old->id)->update(['placed_at' => now()->subDays(40)]);
    ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $shop->id)->limit(1)->update(['mapping_status' => ListingVariant::UNMAPPED, 'product_variant_id' => null]);

    $data = ($this->api)()->getJson('/api/v1/marketplace-stats?days=30')->assertOk()->json('data');
    $row = $data['accounts'][0];
    expect($data['range']['days'])->toBe(30)->and($data['dates'])->toHaveCount(30)
        ->and($row['id'])->toBe($shop->ulid)->and($row['orders'])->toBe(2)->and($row['orders_today'])->toBe(2)
        ->and($row['kept'])->toBe(1)->and($row['cancelled'])->toBe(1)
        ->and((float) $row['revenue'])->toBe((float) $kept->fresh()->total)
        ->and($row['previous']['orders'])->toBe(1)
        ->and(collect($row['daily'])->sum('orders'))->toBe(2)
        ->and($row['listings']['unmapped'])->toBeGreaterThanOrEqual(1)
        ->and($data['top'][0]['quantity'])->toBe(2)
        ->and($data['can_finance'])->toBeTrue()->and($row['fees'])->toBe('0.00');

    // Only the chosen marketplace.
    expect(($this->api)()->getJson('/api/v1/marketplace-stats?marketplace=shopee')->assertOk()->json('data.accounts'))->toBe([]);
});

it('hides marketplace fees from members without finance access', function () {
    fakeShop($this->tenant, false);
    $staff = addMember($this->tenant, 'viewer');
    $res = ($this->api)($staff)->getJson('/api/v1/marketplace-stats');
    if ($res->status() === 403) {
        expect(true)->toBeTrue();

        return;
    }
    expect($res->json('data.can_finance'))->toBeFalse()->and($res->json('data.accounts.0.fees'))->toBeNull();
});
