<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now('Asia/Ho_Chi_Minh')->setDate(2026, 12, 10)->setTime(10, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->forceFill(['settings' => ['store' => ['tax_code' => '8123456789', 'address' => '12 Lê Lợi']]])->save();
    $this->shirt = makeVariant($this->tenant, 'AO-1', ['product_name' => 'Áo thun']);
    $this->repair = makeVariant($this->tenant, 'SUA-1', ['product_name' => 'Sửa máy']);
    $api = actingInTenant($this->owner, $this->tenant);
    $this->service = $api->postJson('/api/v1/categories', ['name' => 'Dịch vụ'])->assertCreated()->json('data.id');
    Product::withoutGlobalScopes()->whereKey($this->repair->product_id)->update(['category_id' => DB::table('categories')->where('ulid', $this->service)->value('id')]);
    stockIn($this->tenant, $this->shirt, 50);
    stockIn($this->tenant, $this->repair, 50);
});

/** A counter order delivered on a given local day. */
function deliveredSale($test, array $lines, string $day): Order
{
    $id = createSalesOrder($test->owner, $test->tenant, $lines)->assertCreated()->json('data.id');
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    $order->forceFill(['status' => 'DELIVERED', 'delivered_at' => now()->parse($day.' 09:00', 'Asia/Ho_Chi_Minh')->utc()])->save();

    return $order;
}

it('keeps tax settings and computes the declaration by industry group, leaving marketplace sales out', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/accounting/settings')->assertOk()->assertJsonPath('data.period', 'quarter')->assertJsonPath('data.rates.goods.vat', 1);
    $api->putJson('/api/v1/accounting/settings', [
        'period' => 'quarter', 'threshold' => 500000000, 'taxpayer_name' => 'Nguyễn Văn Chủ', 'default_group' => 'goods',
        'category_groups' => [$this->service => 'service'], 'include_shipping' => false,
        'rates' => ['goods' => ['vat' => 1, 'pit' => 0.5], 'service' => ['vat' => 5, 'pit' => 2], 'production' => ['vat' => 3, 'pit' => 1.5], 'other' => ['vat' => 2, 'pit' => 1]],
    ])->assertOk()->assertJsonPath('data.category_groups.'.$this->service, 'service');

    deliveredSale($this, [[$this->shirt, 3, 200000]], '2026-10-05'); // 600.000 hàng hoá
    deliveredSale($this, [[$this->shirt, 1, 400000], [$this->repair, 1, 1000000]], '2026-11-20'); // 400.000 + 1.000.000 dịch vụ
    deliveredSale($this, [[$this->shirt, 5, 100000]], '2026-09-30'); // quý 3

    $shop = fakeShop($this->tenant);
    $m = fakeOrder($shop, [[$this->shirt, 2]]);
    $m->forceFill(['status' => 'COMPLETED', 'completed_at' => now()->parse('2026-11-02 10:00', 'Asia/Ho_Chi_Minh')->utc()])->save();
    $marketRevenue = (float) $m->fresh()->subtotal - (float) $m->fresh()->discount;

    $d = $api->getJson('/api/v1/accounting/declaration?period=2026-Q4')->assertOk()->json('data');
    expect($d['label'])->toBe('Quý 4 năm 2026')->and($d['deadline'])->toBe('2027-01-31')
        ->and((float) $d['self'])->toBe(2000000.0)
        ->and((float) $d['vat'])->toBe(10000.0 + 50000.0)   // 1% × 1.000.000 + 5% × 1.000.000
        ->and((float) $d['pit'])->toBe(5000.0 + 20000.0)    // 0,5% + 2%
        ->and((float) $d['market'])->toBe($marketRevenue)
        ->and($d['taxpayer']['name'])->toBe('Nguyễn Văn Chủ')->and($d['orders'])->toBe(2);
    $goods = collect($d['groups'])->firstWhere('group', 'goods');
    expect((float) $goods['self'])->toBe(1000000.0)->and((float) $goods['withheld'])->toBe(round($marketRevenue * 0.015));

    // The default period is the last one that has ended (Q3 on 10/12/2026).
    $api->getJson('/api/v1/accounting/declaration')->assertJsonPath('data.period', '2026-Q3')->assertJsonPath('data.self', 500000);
    $api->get('/api/v1/accounting/declaration?period=2026-Q4&export=1')->assertOk();

    // Overview: year revenue of every channel, own channels apart from the marketplace.
    $o = $api->getJson('/api/v1/accounting/overview?year=2026')->assertOk()->json('data');
    expect((float) $o['revenue'])->toBe(2500000.0 + $marketRevenue)->and((float) $o['self'])->toBe(2500000.0)
        ->and($o['current']['period'])->toBe('2026-Q4')->and($o['todo']['declare']['period'])->toBe('2026-Q3')
        ->and(collect($o['channels'])->pluck('kind')->unique()->sort()->values()->all())->toBe(['market', 'self']);
});

it('lists the revenue book by day and channel, with returns, and the tax book by period', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    deliveredSale($this, [[$this->shirt, 2, 150000]], '2026-11-03');
    deliveredSale($this, [[$this->shirt, 1, 150000]], '2026-11-03');
    $returned = deliveredSale($this, [[$this->shirt, 1, 90000]], '2026-11-07');
    // An approved return of that order on 09/11 comes off that day; a requested one does not count.
    foreach (['approved' => '2026-11-09 08:00', 'requested' => '2026-11-10 08:00'] as $status => $at) {
        $rid = DB::table('order_returns')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'order_id' => $returned->id, 'number' => 'TH-'.$status, 'type' => 'customer_return', 'status' => $status,
            'created_at' => now()->parse($at, 'Asia/Ho_Chi_Minh')->utc(), 'updated_at' => now()]);
        DB::table('order_return_lines')->insert(['tenant_id' => $this->tenant->id, 'order_return_id' => $rid, 'order_item_id' => DB::table('order_items')->where('order_id', $returned->id)->value('id'), 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    $s1 = $api->getJson('/api/v1/accounting/books/s1?month=2026-11')->assertOk()->json('data');
    expect($s1['code'])->toBe('S1-HKD')->and($s1['rows'])->toHaveCount(3)
        ->and((float) $s1['rows'][2]['self'])->toBe(-90000.0)->and($s1['rows'][2]['label'])->toStartWith('Khách trả hàng')
        ->and($s1['rows'][0]['day'])->toBe('2026-11-03')->and((float) $s1['rows'][0]['self'])->toBe(450000.0)->and($s1['rows'][0]['count'])->toBe(2)
        ->and((float) $s1['totals']['self'])->toBe(450000.0);
    $api->get('/api/v1/accounting/books/s1?month=2026-11&export=1')->assertOk();

    $s4 = $api->getJson('/api/v1/accounting/books/s4?month=2026-11')->assertOk()->json('data');
    expect($s4['rows'])->toHaveCount(4)->and((float) $s4['rows'][3]['self'])->toBe(450000.0)->and((float) $s4['totals']['tax'])->toBe(round(450000 * 0.015));

    $api->getJson('/api/v1/accounting/books/s2?month=2026-11')->assertOk()->assertJsonPath('data.code', 'S2-HKD');
    $api->getJson('/api/v1/accounting/books/s3?month=2026-11')->assertOk()->assertJsonPath('data.totals.amount', 0);
    $api->getJson('/api/v1/accounting/books/s9')->assertNotFound();
});

it('keeps tax pages to members who see finance, and settings to managers', function () {
    $staff = addMember($this->tenant, 'ORDER_STAFF');
    $api = actingInTenant($staff, $this->tenant);
    $api->getJson('/api/v1/accounting/overview')->assertForbidden();
    $api->putJson('/api/v1/accounting/settings', [])->assertForbidden();
});
