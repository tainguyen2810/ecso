<?php

use App\Modules\Access\Models\Role;

it('counts sales the way Tài chính does: marketplace orders at completion, delivered ones apart as awaiting', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-REV');
    stockIn($tenant, $variant, 10); // unit cost 50,000
    $shop = fakeShop($tenant);
    $delivered = fakeOrder($shop, [[$variant, 1]], 'fake_delivered');
    $completed = fakeOrder($shop, [[$variant, 2]], 'fake_completed');
    fakeOrder($shop, [[$variant, 1]], 'fake_ready'); // not shipped: neither revenue nor awaiting
    $api = actingInTenant($owner, $tenant);

    $finance = $api->getJson('/api/v1/finance/summary')->assertOk()->json('data');
    $sales = $api->getJson('/api/v1/reports/sales?by=channel')->assertOk()->json('data');
    $goods = fn ($o) => (float) $o->subtotal - (float) $o->discount;

    expect($sales['basis'])->toBe('completed')
        ->and($sales['totals']['orders'])->toBe(1)
        ->and((float) $sales['totals']['goods'])->toEqual($goods($completed))
        ->and((float) $sales['totals']['goods'])->toEqual((float) $finance['completed']['goods'])
        // Every counted order has shipped, so its cost is known: no profit from goods counted at cost 0.
        ->and((float) $sales['totals']['cogs'])->toEqual(100000.0)
        ->and($sales['totals']['missing_cost_lines'])->toBe(0)
        ->and($sales['awaiting'])->toEqual(['orders' => 1, 'goods' => $goods($delivered)]);

    // The dashboard headline is the Lãi lỗ figure itself, and a day of its chart opens the same orders.
    $overview = $api->getJson('/api/v1/reports/overview')->assertOk()->json('data.recognized');
    $pl = collect($api->getJson('/api/v1/reports/profit-loss')->json('data.lines'))->keyBy('key');
    expect($overview['revenue'])->toEqual($pl['revenue']['current'])
        ->and($overview['gross_profit'])->toEqual($pl['gross_profit']['current'])
        ->and($overview['shipping'])->toEqual($pl['shipping']['current']);
    expect($api->getJson('/api/v1/reports/orders?basis=completed')->assertOk()->json('data.totals.orders'))->toBe(1)
        ->and($api->getJson('/api/v1/reports/orders')->json('data.totals.orders'))->toBe(3);

    $products = $api->getJson('/api/v1/reports/product-sales')->assertOk()->json('data');
    expect($products['basis'])->toBe('completed')
        ->and($products['totals']['units'])->toBe(2)
        ->and((float) $products['totals']['cogs'])->toEqual(100000.0);
});

it('takes cost and profit out of the sales, product, order and line reports without finance.profit', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-HIDE');
    stockIn($tenant, $variant, 10);
    fakeOrder(fakeShop($tenant), [[$variant, 1]], 'fake_completed');
    $viewer = addMember($tenant, Role::VIEWER);
    $api = actingInTenant($viewer, $tenant);

    foreach (['/api/v1/reports/sales', '/api/v1/reports/product-sales', '/api/v1/reports/orders', '/api/v1/reports/sales-lines', '/api/v1/reports/sales-lines?export=1'] as $url) {
        $data = $api->getJson($url)->assertOk()->json('data');
        expect($data['profit_hidden'])->toBeTrue()
            ->and($data['rows'])->not->toBeEmpty()
            ->and($data['totals'])->not->toHaveKeys(['cogs', 'gross_profit', 'margin']);
        foreach ($data['rows'] as $row) {
            expect($row)->not->toHaveKeys(['cogs', 'gross_profit', 'margin', 'missing_cost_lines']);
        }
    }

    $owners = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/sales-lines')->assertOk()->json('data');
    expect($owners['profit_hidden'])->toBeFalse()->and($owners['rows'][0])->toHaveKeys(['cogs', 'gross_profit']);
});
