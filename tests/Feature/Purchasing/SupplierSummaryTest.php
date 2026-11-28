<?php

/** Figures above "Nhà cung cấp" and "Đặt hàng nhập / Nhập hàng" (mẫu 28/11). */
it('sums what suppliers are owed, what arrived this month and who comes first', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-01');
    $api = fn () => actingInTenant($owner, $tenant);
    $supplier = $api()->postJson('/api/v1/suppliers', ['name' => 'Xưởng May Minh Long', 'payment_terms_days' => 0])->json('data.id');
    $api()->postJson('/api/v1/suppliers', ['name' => 'Kho Sỉ Chợ Lớn'])->assertCreated();
    $po = $api()->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier, 'warehouse_id' => defaultWarehouse($tenant)->ulid, 'place' => true,
        'lines' => [['variant_id' => $a->ulid, 'quantity' => 10, 'unit_cost' => 50000]],
    ])->json('data.id');
    $api()->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => 10]]])->assertCreated();

    $s = $api()->getJson('/api/v1/suppliers/summary')->assertOk()->json('data');
    expect($s['counts'])->toMatchArray(['all' => 2, 'active' => 2, 'debt' => 1])
        ->and($s['owed'])->toMatchArray(['value' => 500000.0, 'orders' => 1, 'suppliers' => 1])
        ->and($s['month']['value'])->toEqual(500000)
        ->and($s['month']['top'][0]['name'])->toBe('Xưởng May Minh Long')
        ->and($s['due'][0]['id'])->toBe($po);
    $api()->getJson('/api/v1/suppliers?sort=debt')->assertJsonPath('data.0.debt', '500000.00')->assertJsonPath('data.0.next_due', now()->toDateString());

    $r = $api()->getJson('/api/v1/purchase-orders/summary?mode=receiving')->assertOk()->json('data');
    expect($r['month']['value'])->toEqual(500000)->and($r['owed']['value'])->toEqual(500000)->and($r['counts']['unpaid'])->toBe(1);
    $o = $api()->getJson('/api/v1/purchase-orders/summary?mode=ordering')->assertOk()->json('data');
    expect($o['counts'])->toMatchArray(['all' => 0, 'ordered' => 0]);
});
