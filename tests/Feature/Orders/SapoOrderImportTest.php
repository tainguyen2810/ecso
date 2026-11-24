<?php

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Services\CustomerDebt;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;

/** Cấu hình › Chuyển dữ liệu › Đơn hàng cũ: the files saved by the "Lấy đơn hàng Sapo" bookmark. */
function sapoOrdersFile(array $orders, array $returns = [], string $name = 'sapo-don-hang-cuahang-01.json'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, json_encode([
        'format' => 'ezsell-sapo-go-orders', 'version' => 1, 'store' => 'cuahang.mysapogo.com', 'part' => 1, 'parts' => 1,
        'orders' => $orders, 'returns' => $returns,
    ]));
}

function sapoOrder(string $code, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => crc32($code), 'code' => $code, 'reference' => null, 'status' => 'completed', 'fulfillment_status' => 'shipped', 'payment_status' => 'paid',
        'created_on' => '2025-03-01T02:00:00Z', 'completed_on' => '2025-03-02T03:00:00Z', 'source' => 'Facebook', 'location' => null, 'staff' => 'Lan',
        'customer' => ['id' => 77, 'code' => null, 'name' => 'Trần Minh Khoa', 'phone' => '0901234567', 'email' => null],
        'address' => '12 Lê Lợi, TP Hồ Chí Minh', 'note' => null, 'tags' => [], 'total' => 250000, 'discount' => 10000, 'shipping_fee' => 20000, 'tax' => 0, 'paid' => 250000,
        'cod' => 0, 'carrier' => null, 'tracking' => null,
        'lines' => [['id' => 1, 'sku' => 'USB-AC600', 'barcode' => null, 'name' => 'USB Wifi AC600', 'variant' => 'Đen', 'unit' => 'Cái', 'quantity' => 2, 'price' => 120000, 'discount' => 0, 'cost' => null, 'service' => false]],
    ], $overrides);
}

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->usb = makeVariant($this->tenant, 'USB-AC600', ['product_name' => 'USB Wifi AC600']);
    stockIn($this->tenant, $this->usb, 10);
    $this->orders = fn () => Order::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('imported_from', 'sapo');
});

it('checks a file first, then brings the orders over as history that never touches stock', function () {
    $orders = [
        sapoOrder('SON1'),
        sapoOrder('SON2', ['status' => 'cancelled', 'cancelled_on' => '2025-03-05T01:00:00Z', 'completed_on' => null]),
        sapoOrder('SON3', ['lines' => [['sku' => 'KHONG-CO', 'name' => 'Cáp cũ', 'quantity' => 1, 'price' => 50000]], 'total' => 50000, 'discount' => 0, 'shipping_fee' => 0]),
        // Still being handled in Sapo: left there until it is finished.
        sapoOrder('SON4', ['status' => 'finalized', 'fulfillment_status' => 'unshipped', 'completed_on' => null]),
    ];

    $check = $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile($orders), 'dry_run' => true])->assertOk();
    expect($check->json('created'))->toBe(3)
        ->and($check->json('skipped_open'))->toBe(1)
        ->and($check->json('unmatched_lines'))->toBe(1)
        ->and($check->json('unmatched.0.name'))->toBe('KHONG-CO')
        ->and($check->json('from'))->toBe('2025-03-01')
        ->and((float) $check->json('revenue'))->toBe(300000.0)
        ->and(($this->orders)()->count())->toBe(0);

    $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile($orders)])->assertOk()->assertJsonPath('created', 3);

    TenantContext::run($this->tenant, function () {
        $son1 = ($this->orders)()->where('external_order_id', 'SON1')->sole();
        expect($son1->historical)->toBeTrue()
            ->and($son1->status)->toBe(OrderStatus::COMPLETED)
            ->and($son1->marketplace)->toBe(Order::SOURCE_MANUAL)
            ->and($son1->total)->toBe('250000.00')
            ->and($son1->payment_status)->toBe(Order::PAYMENT_PAID)
            ->and($son1->delivered_at->toDateString())->toBe('2025-03-02')
            ->and($son1->items()->sole()->product_variant_id)->toBe($this->usb->id)
            ->and(SalesChannel::withoutGlobalScopes()->find($son1->sales_channel_id)->name)->toBe('Facebook')
            ->and(Customer::withoutGlobalScopes()->find($son1->customer_id)->name)->toBe('Trần Minh Khoa')
            ->and(($this->orders)()->where('external_order_id', 'SON2')->value('status'))->toBe(OrderStatus::CANCELLED)
            ->and(($this->orders)()->where('external_order_id', 'SON4')->exists())->toBeFalse();
    });

    // Sales reports count it like the shop's own orders, by the Sapo channel.
    $report = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/reports/sales?from=2025-03-01&to=2025-03-31&by=channel')->assertOk();
    expect(collect($report->json('data.rows'))->firstWhere('label', 'Facebook')['revenue'] ?? null)->toEqual(300000);

    // Stock stays what was brought over with the products.
    expect(balanceOf(defaultWarehouse($this->tenant), $this->usb)->on_hand)->toBe(10)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->usb)->reserved ?? 0)->toBe(0);
});

it('does not make the same order twice and brings a finished order up to date', function () {
    $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile([sapoOrder('SON1', ['payment_status' => 'partial', 'paid' => 100000])])])->assertOk();
    $again = $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile([sapoOrder('SON1'), sapoOrder('SON5')])])->assertOk();

    expect($again->json('created'))->toBe(1)->and($again->json('updated'))->toBe(1)
        ->and(($this->orders)()->count())->toBe(2)
        ->and(($this->orders)()->where('external_order_id', 'SON1')->value('payment_status'))->toBe(Order::PAYMENT_PAID);
});

it('finds a product whose Sapo SKU had a space the product import turned into "-"', function () {
    makeVariant($this->tenant, 'CU-GF63-8rd', ['product_name' => 'Cục sạc GF63']);
    $check = $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile([sapoOrder('SON9', ['lines' => [['sku' => 'CU-GF63 8rd', 'name' => 'Cục sạc GF63']]])]), 'dry_run' => true])->assertOk();

    expect($check->json('unmatched_lines'))->toBe(0);
});

it('leaves out a marketplace order that already came in through the shop connection', function () {
    $shop = fakeShop($this->tenant);
    $synced = fakeOrder($shop, [[$this->usb, 1]]);

    $check = $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile([sapoOrder('SON9', ['reference' => $synced->external_order_id, 'source' => 'Shopee'])]), 'dry_run' => true])->assertOk();

    expect($check->json('skipped_synced'))->toBe(1)->and($check->json('created'))->toBe(0);
});

it('carries no debt twice, cannot be paid here and shows in the order list with its source', function () {
    $customer = TenantContext::run($this->tenant, function () {
        $customer = new Customer;
        $customer->forceFill(['code' => 'CUZN01', 'name' => 'Công ty Ánh Dương', 'status' => 'active'])->save();

        return $customer;
    });
    $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile([
        sapoOrder('SON1', ['customer' => ['code' => 'CUZN01', 'name' => 'Công ty Ánh Dương', 'phone' => null], 'status' => 'finalized', 'payment_status' => 'unpaid', 'paid' => 0,
            'completed_on' => null, 'created_on' => now()->subMonths(2)->toIso8601String()]),
    ])])->assertOk();

    $order = ($this->orders)()->sole();
    expect($order->customer_id)->toBe($customer->id)
        ->and($order->status)->toBe(OrderStatus::DELIVERED)
        ->and($order->payment_status)->toBe(Order::PAYMENT_UNPAID);

    // The unpaid part came over as the customer's opening debt (Khách hàng file): not counted twice.
    $debt = TenantContext::run($this->tenant, fn () => Customer::query()->whereKey($customer->id)->selectRaw(CustomerDebt::customerDebtSql().' as debt')->value('debt'));
    expect((float) $debt)->toBe(0.0);

    postOnce($this->owner, $this->tenant, "/api/v1/orders/{$order->ulid}/payments", ['amount' => 1000, 'method' => 'cash'], 'pay-sapo')->assertStatus(422);

    $list = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/orders?source=sapo')->assertOk();
    expect($list->json('data.0.imported_from'))->toBe('sapo')->and($list->json('meta.has_imported'))->toBeTrue();
});

it('links returns to their order without putting the goods back in stock', function () {
    $this->api->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile([sapoOrder('SON1')], [
        ['id' => 5, 'code' => 'SRN5', 'order_id' => crc32('SON1'), 'order_code' => 'SON1', 'status' => 'received', 'created_on' => '2025-03-04T02:00:00Z', 'received_on' => '2025-03-04T03:00:00Z',
            'reason' => 'Lỗi', 'refund' => 120000, 'lines' => [['sku' => 'USB-AC600', 'line_id' => 1, 'quantity' => 1, 'price' => 120000]]],
    ])])->assertOk()->assertJsonPath('returns', 1);

    $return = OrderReturn::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
    expect($return->status)->toBe(OrderReturn::CLOSED)
        ->and($return->refund_amount)->toBe('120000.00')
        ->and(TenantContext::run($this->tenant, fn () => $return->lines()->sole()->quantity))->toBe(1)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->usb)->on_hand)->toBe(10);
});

it('refuses a file that is not from the bookmark and staff without the right', function () {
    $this->api->postJson('/api/v1/orders/import/sapo', ['file' => UploadedFile::fake()->createWithContent('x.json', '{"format":"other"}')])->assertUnprocessable();

    $agent = addMember($this->tenant, 'WAREHOUSE_STAFF');
    actingInTenant($agent, $this->tenant)->postJson('/api/v1/orders/import/sapo', ['file' => sapoOrdersFile([sapoOrder('SON1')])])->assertForbidden();
});
