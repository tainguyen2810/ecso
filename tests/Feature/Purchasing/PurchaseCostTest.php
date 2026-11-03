<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use Illuminate\Support\Facades\DB;

function poPayload($tenant, string $supplier, array $lines, array $extra = []): array
{
    return [
        'supplier_id' => $supplier,
        'warehouse_id' => defaultWarehouse($tenant)->ulid,
        'lines' => array_map(fn ($l) => ['variant_id' => $l[0]->ulid, 'quantity' => $l[1], 'unit_cost' => $l[2], 'tax_rate' => $l[3] ?? 0], $lines),
        ...$extra,
    ];
}

it('adds input VAT per line and spreads purchase costs into the landed cost by value', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-01');
    $b = makeVariant($tenant, 'QUAN-01');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'Minh Long'])->json('data.id');

    $po = actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$a, 10, 100000, 10], [$b, 20, 50000, 8]], [
        'extra_cost' => 200000, 'extra_cost_note' => 'Xe tải giao hàng', 'place' => true,
    ]))->assertCreated();
    expect($po->json('data.subtotal'))->toBe('2000000.00')
        ->and($po->json('data.tax_amount'))->toBe('180000.00')
        ->and($po->json('data.total'))->toBe('2180000.00') // freight paid to the carrier, not owed to the supplier
        ->and($po->json('data.lines.0.landed_unit_cost'))->toBe('110000.00')
        ->and($po->json('data.lines.1.landed_unit_cost'))->toBe('55000.00');
    $id = $po->json('data.id');

    $res = actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$id}/receive", [
        'lines' => [['variant_id' => $a->ulid, 'quantity' => 10], ['variant_id' => $b->ulid, 'quantity' => 20]],
    ])->assertCreated();
    expect($res->json('data.received_value'))->toBe('2180000.00')
        ->and($res->json('data.received_tax'))->toBe('180000.00')
        ->and($res->json('data.received_extra_cost'))->toBe('200000.00')
        ->and($res->json('data.balance_due'))->toBe('2180000.00');

    // Deductible VAT stays out of stock value; the freight share goes in.
    expect((float) ProductVariant::withoutGlobalScopes()->find($a->id)->cost_price)->toBe(110000.0)
        ->and((float) ProductVariant::withoutGlobalScopes()->find($b->id)->cost_price)->toBe(55000.0);
    $receipt = StockReceipt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->first();
    expect((float) $receipt->lines()->sum(DB::raw('quantity * unit_cost')))->toBe(2200000.0);

    // Returning goods takes the VAT back too.
    $ret = actingInTenant($owner, $tenant)->postJson('/api/v1/supplier-returns', [
        'purchase_order_id' => $id, 'lines' => [['variant_id' => $a->ulid, 'quantity' => 2]],
    ])->assertCreated();
    expect($ret->json('data.total'))->toBe('220000.00')->and($ret->json('data.tax_amount'))->toBe('20000.00');
    actingInTenant($owner, $tenant)->getJson("/api/v1/purchase-orders/{$id}")->assertJsonPath('data.balance_due', '1960000.00');
});

it('carries VAT in the cost when it is not deductible, and owes freight on the supplier invoice', function () {
    [$tenant, $owner] = tenantWithOwner();
    $c = makeVariant($tenant, 'MU-01');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'An Phát'])->json('data.id');

    $id = actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$c, 4, 25000, 10]], [
        'extra_cost' => 20000, 'extra_cost_to_supplier' => true, 'vat_in_cost' => true,
    ]))->assertCreated()->assertJsonPath('data.total', '130000.00')->json('data.id');

    // Draft: changing the costs recomputes the total.
    actingInTenant($owner, $tenant)->putJson("/api/v1/purchase-orders/{$id}", ['extra_cost' => 0])
        ->assertOk()->assertJsonPath('data.total', '110000.00');
    actingInTenant($owner, $tenant)->putJson("/api/v1/purchase-orders/{$id}", ['extra_cost' => 20000])
        ->assertOk()->assertJsonPath('data.total', '130000.00');

    // Half arrives at a higher price: 30 000 + 10 % VAT + 5 000 freight share.
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$id}/receive", ['lines' => [['variant_id' => $c->ulid, 'quantity' => 2, 'unit_cost' => 30000]]])
        ->assertCreated()
        ->assertJsonPath('data.received_value', '76000.00')
        ->assertJsonPath('data.received_extra_cost', '10000.00');
    expect((float) ProductVariant::withoutGlobalScopes()->find($c->id)->cost_price)->toBe(38000.0);
});

it('rejects an impossible VAT rate', function () {
    [$tenant, $owner] = tenantWithOwner();
    $c = makeVariant($tenant, 'MU-01');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'An Phát'])->json('data.id');

    actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$c, 1, 1000, 150]]))
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_rate');
});

it('takes the order discount off the debt and the landed cost, and keeps invoice details', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'CK-A');
    $b = makeVariant($tenant, 'CK-B');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'Viễn Sơn'])->json('data.id');

    $po = actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$a, 10, 100000], [$b, 20, 50000]], [
        'discount' => 100000, 'invoice_date' => '2026-11-04', 'reference' => 'HĐ 0001254', 'tags' => ['cuối tháng'], 'place' => true,
    ]))->assertCreated();
    expect($po->json('data.total'))->toBe('1900000.00')
        ->and($po->json('data.discount'))->toBe('100000.00')
        ->and($po->json('data.invoice_date'))->toBe('2026-11-04')
        ->and($po->json('data.reference'))->toBe('HĐ 0001254')
        ->and($po->json('data.tags'))->toBe(['cuối tháng'])
        ->and($po->json('data.lines.0.landed_unit_cost'))->toBe('95000.00');
    $id = $po->json('data.id');

    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$id}/receive", [
        'lines' => [['variant_id' => $a->ulid, 'quantity' => 10], ['variant_id' => $b->ulid, 'quantity' => 20]],
    ])->assertCreated()->assertJsonPath('data.received_value', '1900000.00');
    expect((float) ProductVariant::withoutGlobalScopes()->find($a->id)->cost_price)->toBe(95000.0)
        ->and((float) ProductVariant::withoutGlobalScopes()->find($b->id)->cost_price)->toBe(47500.0);

    // A return is credited at the discounted price.
    actingInTenant($owner, $tenant)->postJson('/api/v1/supplier-returns', ['purchase_order_id' => $id, 'lines' => [['variant_id' => $a->ulid, 'quantity' => 1]]])
        ->assertCreated()->assertJsonPath('data.total', '95000.00');

    // Searching by the supplier's reference finds the order; a discount above the goods is refused.
    actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders?q=0001254')->assertJsonCount(1, 'data');
    actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$a, 1, 1000]], ['discount' => 5000]))
        ->assertUnprocessable()->assertJsonValidationErrors('discount');
});

it('creates and receives at once and pays the supplier in the same step (Tạo & nhập hàng)', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'TN-A');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'ReSellerKey'])->json('data.id');

    $res = actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$a, 5, 40000]], [
        'receive' => true, 'payment' => ['amount' => 150000, 'method' => 'bank_transfer'],
    ]))->assertCreated();
    expect($res->json('data.status'))->toBe('received')
        ->and($res->json('data.received_value'))->toBe('200000.00')
        ->and($res->json('data.paid_amount'))->toBe('150000.00')
        ->and($res->json('data.balance_due'))->toBe('50000.00')
        ->and($res->json('data.receipts'))->toHaveCount(1);
    expect(balanceOf(defaultWarehouse($tenant), $a)->on_hand)->toBe(5);

    // "Tạo & chưa nhập" may still prepay up to the order total, not more.
    $placed = actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$a, 2, 40000]], [
        'place' => true, 'payment' => ['amount' => 80000, 'method' => 'cash'],
    ]))->assertCreated();
    expect($placed->json('data.status'))->toBe('ordered')->and($placed->json('data.paid_amount'))->toBe('80000.00');
    actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', poPayload($tenant, $supplier, [[$a, 2, 40000]], [
        'place' => true, 'payment' => ['amount' => 90000, 'method' => 'cash'],
    ]))->assertUnprocessable()->assertJsonValidationErrors('amount');
    // Nothing of the refused order was kept.
    actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders')->assertJsonCount(2, 'data');

    // Sapo's tabs.
    actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders?status=done')->assertJsonCount(1, 'data');
    actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders?status=trading')->assertJsonCount(1, 'data');
    actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders?status=ordering')->assertJsonCount(1, 'data');
    actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders?q=TN-A')->assertJsonCount(2, 'data');
});
