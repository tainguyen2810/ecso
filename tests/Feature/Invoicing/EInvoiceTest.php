<?php

use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
    stockIn($this->tenant, $this->shirt, 50);
    $this->api = fn () => actingInTenant($this->owner, $this->tenant);
    $this->connectFake = fn () => ($this->api)()->postJson('/api/v1/einvoice/account', [
        'provider' => 'fake', 'environment' => 'sandbox', 'credentials' => ['client_id' => 'demo', 'client_secret' => 'sk-hidden-42'],
    ]);
});

it('connects a provider, lists its templates and never shows the secrets', function () {
    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'fake', 'environment' => 'sandbox', 'credentials' => ['client_id' => 'demo', 'client_secret' => 'wrong']])
        ->assertStatus(422)->assertJsonValidationErrors('credentials');

    ($this->connectFake)()->assertOk()
        ->assertJsonPath('data.template', '1|C26TEZ')
        ->assertJsonCount(2, 'data.templates')
        ->assertJsonPath('data.credentials_set', ['client_id', 'client_secret'])
        ->assertJsonMissingPath('data.credentials');

    ($this->api)()->putJson('/api/v1/einvoice/account', ['template' => '2|C26TEZ', 'issue_mode' => 'paid', 'send_email' => false])->assertOk()
        ->assertJsonPath('data.template', '2|C26TEZ')->assertJsonPath('data.issue_mode', 'paid')->assertJsonPath('data.send_email', false);
    ($this->api)()->putJson('/api/v1/einvoice/account', ['template' => '9|X'])->assertStatus(422);

    ($this->api)()->getJson('/api/v1/einvoice/settings')->assertOk()
        ->assertJson(fn ($json) => $json->where('data.providers', fn ($p) => collect($p)->firstWhere('provider', 'misa')['available'] === false)->etc());
    expect(json_encode(($this->api)()->getJson('/api/v1/einvoice/settings')->json()))->not->toContain('sk-hidden-42');

    $staff = addMember($this->tenant, 'ORDER_STAFF');
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/einvoice/account', ['provider' => 'fake', 'environment' => 'sandbox', 'credentials' => ['client_id' => 'x', 'client_secret' => 'y']])->assertForbidden();
});

it('issues one invoice per order with the buyer taken from the order', function () {
    ($this->connectFake)()->assertOk();
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 2, 150000]], ['customer' => ['name' => 'Nguyễn Văn A', 'phone' => '0912345678']])->json('data.id');

    ($this->api)()->getJson("/api/v1/orders/{$id}/einvoice")->assertOk()
        ->assertJsonPath('data.ready', true)->assertJsonPath('data.buyer.name', 'Nguyễn Văn A')->assertJsonCount(0, 'data.invoices');

    ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.invoice_series', 'C26TEZ')
        ->assertJsonPath('data.total', '300000.00')
        ->assertJsonPath('data.invoice_number', fn ($n) => strlen($n) === 8)
        ->assertJsonPath('data.pdf_url', fn ($u) => str_ends_with($u, '.pdf'));
    ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertStatus(422)->assertJsonValidationErrors('einvoice');
    ($this->api)()->getJson("/api/v1/orders/{$id}/einvoice")->assertJsonCount(1, 'data.invoices');
});

it('keeps a failed request and asks again with the same reference code', function () {
    ($this->connectFake)()->assertOk();
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1]])->json('data.id');

    $failed = ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice", ['buyer' => ['name' => 'LỖI']])->assertCreated()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.message', 'Thông tin người mua không hợp lệ.')->json('data');
    ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice", ['buyer' => ['name' => 'Cty PMT', 'tax_code' => '12345']])->assertStatus(422)->assertJsonValidationErrors('buyer.tax_code');

    ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice", ['buyer' => ['name' => 'Cty PMT', 'tax_code' => '0109876543']])->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.reference_code', $failed['reference_code'])
        ->assertJsonPath('data.buyer.tax_code', '0109876543');
    expect(EInvoice::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses cancelled orders and shops without a connection', function () {
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1]])->json('data.id');
    ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertStatus(422)->assertJsonPath('errors.einvoice.0', fn ($m) => str_contains($m, 'Chưa kết nối'));

    ($this->connectFake)()->assertOk();
    ($this->api)()->postJson("/api/v1/orders/{$id}/actions/cancel", ['reason' => 'Khách hủy'])->assertOk();
    ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertStatus(422)->assertJsonPath('errors.einvoice.0', 'Đơn đã hủy, không xuất hóa đơn.');
});

it('issues automatically when an own-channel order is fully paid, if the shop chose so', function () {
    ($this->connectFake)()->assertOk();
    ($this->api)()->putJson('/api/v1/einvoice/account', ['issue_mode' => 'paid'])->assertOk();

    $paid = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1, 200000]], ['payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data.id');
    $unpaid = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1, 200000]])->json('data.id');

    ($this->api)()->getJson("/api/v1/orders/{$paid}/einvoice")->assertJsonPath('data.invoices.0.status', 'issued');
    ($this->api)()->getJson("/api/v1/orders/{$unpaid}/einvoice")->assertJsonCount(0, 'data.invoices');

    ($this->api)()->postJson("/api/v1/orders/{$unpaid}/payments", ['kind' => 'payment', 'method' => 'cash', 'amount' => 200000])->assertCreated();
    ($this->api)()->getJson("/api/v1/orders/{$unpaid}/einvoice")->assertJsonPath('data.invoices.0.status', 'issued');
});

it('talks to SePay eInvoice: token, provider account, create and status check', function () {
    Http::fake([
        'einvoice-api-sandbox.sepay.vn/v1/token' => Http::response(['success' => true, 'data' => ['access_token' => 'tok-1', 'token_type' => 'Bearer', 'expires_in' => 86400]]),
        'einvoice-api-sandbox.sepay.vn/v1/provider-accounts?*' => Http::response(['success' => true, 'data' => ['items' => [['id' => 'acc-uuid', 'provider' => 'matbao', 'active' => true]]]]),
        'einvoice-api-sandbox.sepay.vn/v1/provider-accounts/acc-uuid' => Http::response(['success' => true, 'data' => ['id' => 'acc-uuid', 'templates' => [['template_code' => '1', 'invoice_series' => 'C26TSE', 'invoice_label' => '1 - C26TSE - Hóa đơn giá trị gia tăng']]]]),
        'einvoice-api-sandbox.sepay.vn/v1/invoices/create' => Http::response(['success' => true, 'data' => ['tracking_code' => 'trk-9']]),
        'einvoice-api-sandbox.sepay.vn/v1/invoices/create/check/trk-9' => Http::response(['success' => true, 'data' => ['status' => 'Success', 'message' => 'Xuất hóa đơn điện tử thành công', 'invoice' => [
            'invoice_number' => '0000045', 'issued_date' => '2026-11-13', 'pdf_url' => 'https://portal.example/inv.pdf', 'status' => 'issued',
        ]]]),
    ]);

    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'sepay', 'environment' => 'sandbox', 'credentials' => ['client_id' => 'cid', 'client_secret' => 'csecret']])->assertOk()
        ->assertJsonPath('data.provider_name', 'matbao')->assertJsonPath('data.template', '1|C26TSE');
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 2, 150000]], ['shipping_fee' => 30000])->json('data.id');

    ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertCreated()
        ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.invoice_number', '0000045')->assertJsonPath('data.pdf_url', 'https://portal.example/inv.pdf');

    Http::assertSent(function (Request $r) {
        if (! str_ends_with($r->url(), '/v1/invoices/create')) {
            return false;
        }
        $body = $r->data();

        return $r->hasHeader('Authorization', 'Bearer tok-1')
            && $body['provider_account_id'] === 'acc-uuid' && $body['is_draft'] === false
            && $body['template_code'] === '1' && $body['invoice_series'] === 'C26TSE'
            && $body['items'][0]['item_code'] === 'AO-001' && $body['items'][0]['quantity'] === 2
            && $body['items'][1]['item_name'] === 'Phí vận chuyển' && $body['items'][1]['tax_rate'] === -2
            && $body['total_amount'] === 330000 && str_starts_with($body['reference_code'], 'EZ');
    });
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/token') && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:csecret')));
    expect(EInvoiceAccount::withoutGlobalScopes()->first()->getRawOriginal('credentials'))->not->toContain('csecret');
});

it('reports SePay refusing the credentials', function () {
    Http::fake(['einvoice-api.sepay.vn/v1/token' => Http::response(['success' => false, 'error' => ['code' => 'BAD_REQUEST', 'message' => 'Invalid client']], 401)]);

    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'sepay', 'environment' => 'production', 'credentials' => ['client_id' => 'cid', 'client_secret' => 'bad']])
        ->assertStatus(422)->assertJsonPath('errors.credentials.0', fn ($m) => str_contains($m, 'không nhận Client ID'));
});
