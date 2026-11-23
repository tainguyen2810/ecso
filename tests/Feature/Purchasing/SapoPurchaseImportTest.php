<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;

/** Cấu hình › Chuyển dữ liệu › Nhà cung cấp và nhập hàng: the file saved by the "Lấy nhập hàng Sapo" bookmark. */
function sapoPurchasesFile(array $purchases, array $suppliers = [], array $returns = []): UploadedFile
{
    return UploadedFile::fake()->createWithContent('sapo-nhap-hang-cuahang.json', json_encode([
        'format' => 'ezsell-sapo-go-purchases', 'version' => 1, 'store' => 'cuahang.mysapogo.com',
        'suppliers' => $suppliers, 'purchases' => $purchases, 'returns' => $returns,
    ]));
}

function sapoPurchase(string $code, array $overrides = []): array
{
    return array_replace([
        'id' => crc32($code), 'code' => $code, 'reference' => null, 'status' => 'completed', 'receive_status' => 'received', 'payment_status' => 'paid',
        'created_on' => '2025-04-01T02:00:00Z', 'received_on' => '2025-04-02T03:00:00Z', 'completed_on' => '2025-04-02T03:00:00Z', 'location' => null, 'staff' => 'Lan',
        'supplier' => ['id' => 9, 'code' => 'SUPN00001', 'name' => 'Công ty Linh Kiện Sao Mai'],
        'note' => null, 'tags' => [], 'discount' => 0, 'extra_cost' => 0, 'tax' => 0, 'total' => 1000000, 'paid' => 1000000,
        'payments' => [['amount' => 1000000, 'method' => 'Tiền mặt', 'paid_on' => '2025-04-02T03:00:00Z']],
        'lines' => [['sku' => 'USB-AC600', 'name' => 'USB Wifi AC600', 'quantity' => 10, 'received' => 10, 'price' => 100000, 'discount' => 0]],
    ], $overrides);
}

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->usb = makeVariant($this->tenant, 'USB-AC600', ['product_name' => 'USB Wifi AC600']);
    stockIn($this->tenant, $this->usb, 7);
    $this->orders = fn () => PurchaseOrder::withoutGlobalScopes()->where('tenant_id', $this->tenant->id);
    $this->supplierList = [['id' => 9, 'code' => 'SUPN00001', 'name' => 'Công ty Linh Kiện Sao Mai', 'phone' => '0281234567', 'status' => 'active']];
});

it('checks a file first, then brings suppliers and orders over with the debt Sapo has, never touching stock or the cash book', function () {
    $purchases = [
        sapoPurchase('PON001'),
        // Received, half paid: 600,000 still owed.
        sapoPurchase('PON002', ['status' => 'active', 'payment_status' => 'partial', 'total' => 1200000, 'paid' => 600000,
            'payments' => [['amount' => 600000, 'method' => 'Chuyển khoản', 'paid_on' => '2025-05-03T03:00:00Z']], 'created_on' => '2025-05-01T02:00:00Z', 'received_on' => '2025-05-02T02:00:00Z',
            'lines' => [['sku' => 'USB-AC600', 'quantity' => 12, 'price' => 100000], ['sku' => 'KHONG-CO', 'name' => 'Cáp cũ', 'quantity' => 1, 'price' => 0]]]),
        sapoPurchase('PON003', ['status' => 'cancelled', 'receive_status' => 'pending', 'paid' => 0, 'payments' => []]),
        // Still waiting for the goods in Sapo: left there.
        sapoPurchase('PON004', ['status' => 'active', 'receive_status' => 'pending', 'paid' => 0, 'payments' => []]),
    ];

    $check = $this->api->postJson('/api/v1/purchase-orders/import/sapo', ['file' => sapoPurchasesFile($purchases, $this->supplierList), 'dry_run' => true])->assertOk();
    expect($check->json('created'))->toBe(3)
        ->and($check->json('skipped_open'))->toBe(1)
        ->and($check->json('cancelled'))->toBe(1)
        ->and($check->json('suppliers_new'))->toBe(1)
        ->and($check->json('unmatched.0.name'))->toBe('KHONG-CO')
        ->and((float) $check->json('value'))->toBe(2200000.0)
        ->and((float) $check->json('debt'))->toBe(600000.0)
        ->and(($this->orders)()->count())->toBe(0);

    $this->api->postJson('/api/v1/purchase-orders/import/sapo', ['file' => sapoPurchasesFile($purchases, $this->supplierList)])->assertOk()->assertJsonPath('created', 3);

    $supplier = Supplier::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
    expect($supplier->code)->toBe('SUPN00001')->and($supplier->phone)->toBe('0281234567');
    $this->api->getJson("/api/v1/suppliers/{$supplier->ulid}")->assertOk()->assertJsonPath('data.debt', '600000.00');

    TenantContext::run($this->tenant, function () {
        $po = PurchaseOrder::query()->where('number', 'PON002')->sole();
        expect($po->status)->toBe(PurchaseOrder::RECEIVED)
            ->and($po->imported_from)->toBe('sapo')
            ->and($po->paid_amount)->toBe('600000.00')
            ->and($po->lines()->sole()->received_qty)->toBe(12)
            ->and(PurchaseOrder::query()->where('number', 'PON003')->value('status'))->toBe(PurchaseOrder::CANCELLED)
            ->and(PurchaseOrder::query()->where('number', 'PON004')->exists())->toBeFalse()
            ->and(SupplierPayment::query()->where('purchase_order_id', $po->id)->value('method'))->toBe('bank_transfer')
            // The SKU now knows who it is bought from.
            ->and(ProductVariant::query()->find($this->usb->id)->supplier_id)->toBe(PurchaseOrder::query()->where('number', 'PON002')->value('supplier_id'));
    });

    // Stock stays what was brought over with the products, and Sapo's payments are not in this cash book.
    expect(balanceOf(defaultWarehouse($this->tenant), $this->usb)->on_hand)->toBe(7);
    $book = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/reports/cashbook?from=2025-04-01&to=2025-05-31')->assertOk()->json('data');
    expect((float) ($book['out'] ?? 0))->toBe(0.0);

    // The purchase report counts the orders like goods received here.
    $report = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/reports/purchases?from=2025-04-01&to=2025-05-31&by=supplier')->assertOk();
    expect($report->json('data.rows.0.label'))->toBe('Công ty Linh Kiện Sao Mai')->and((float) $report->json('data.rows.0.value'))->toBe(2200000.0);

    // What is still owed can be paid here as usual; Sapo's own payments cannot be voided.
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/suppliers/{$supplier->ulid}/payments", ['purchase_order_id' => TenantContext::run($this->tenant, fn () => PurchaseOrder::query()->where('number', 'PON002')->value('ulid')), 'method' => 'cash', 'amount' => 600000])->assertCreated();
    $imported = SupplierPayment::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->whereNotNull('imported_from')->first();
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/supplier-payments/{$imported->ulid}/void", ['reason' => 'x'])->assertUnprocessable();
});

it('does not make the same order twice and brings payments up to date', function () {
    $this->api->postJson('/api/v1/purchase-orders/import/sapo', ['file' => sapoPurchasesFile([sapoPurchase('PON001', ['paid' => 400000, 'payments' => [['amount' => 400000, 'method' => 'cash']]])])])->assertOk();
    $again = $this->api->postJson('/api/v1/purchase-orders/import/sapo', ['file' => sapoPurchasesFile([sapoPurchase('PON001'), sapoPurchase('PON005')])])->assertOk();

    expect($again->json('created'))->toBe(1)->and($again->json('updated'))->toBe(1)
        ->and(($this->orders)()->count())->toBe(2)
        ->and(($this->orders)()->where('number', 'PON001')->value('paid_amount'))->toBe('1000000.00')
        ->and(SupplierPayment::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(Supplier::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('links returns to their order, lowers the debt and keeps stock', function () {
    $this->api->postJson('/api/v1/purchase-orders/import/sapo', ['file' => sapoPurchasesFile(
        [sapoPurchase('PON001', ['paid' => 0, 'payments' => []])],
        $this->supplierList,
        [
            // Returned and refunded: no change to what is owed.
            ['code' => 'PRN01', 'purchase_code' => 'PON001', 'status' => 'completed', 'created_on' => '2025-04-05T02:00:00Z', 'reason' => 'Lỗi', 'total' => 200000, 'refunded' => 200000,
                'lines' => [['sku' => 'USB-AC600', 'quantity' => 2, 'price' => 100000]]],
            // Returned, not refunded: owed less.
            ['code' => 'PRN02', 'purchase_code' => 'PON001', 'status' => 'completed', 'created_on' => '2025-04-06T02:00:00Z', 'total' => 100000, 'refunded' => 0,
                'lines' => [['sku' => 'USB-AC600', 'quantity' => 1, 'price' => 100000]]],
        ],
    )])->assertOk()->assertJsonPath('returns', 2);

    $po = ($this->orders)()->sole();
    expect($po->returned_value)->toBe('300000.00')
        ->and(SupplierReturn::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(TenantContext::run($this->tenant, fn () => $po->lines()->sole()->returned_qty))->toBe(3)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->usb)->on_hand)->toBe(7);
    $supplier = Supplier::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
    // 1,000,000 received − 300,000 returned + 200,000 refunded.
    $this->api->getJson("/api/v1/suppliers/{$supplier->ulid}")->assertOk()->assertJsonPath('data.debt', '900000.00');
});

it('refuses a file that is not from the bookmark and staff without the right', function () {
    $this->api->postJson('/api/v1/purchase-orders/import/sapo', ['file' => UploadedFile::fake()->createWithContent('x.json', '{"format":"other"}')])->assertUnprocessable();

    $agent = addMember($this->tenant, 'WAREHOUSE_STAFF');
    actingInTenant($agent, $this->tenant)->postJson('/api/v1/purchase-orders/import/sapo', ['file' => sapoPurchasesFile([sapoPurchase('PON001')])])->assertForbidden();
});
