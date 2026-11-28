<?php

use App\Modules\Identity\Models\User;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Models\EInvoiceOrder;
use App\Modules\Invoicing\Models\EInvoiceProviderRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = fn () => actingInTenant($this->owner, $this->tenant);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
    $this->asAdmin = function () {
        Sanctum::actingAs($this->admin);

        return $this;
    };
    $this->company = ['type' => 'company', 'tax_code' => '0312345678', 'name' => 'CÔNG TY TNHH MINH PHÁT', 'address' => '1 Lê Lợi, Q1', 'representative' => 'Nguyễn Văn An', 'email' => 'ketoan@example.com', 'phone' => '0901234567'];
});

it('lets a super admin sell packages that a shop buys, pays, and gets connected', function () {
    config(['billing.bank' => ['name' => 'Vietcombank', 'account_number' => '0123456789', 'account_name' => 'CONG TY ABC', 'branch' => null]]);

    // No package yet: the shop sees nothing to buy.
    ($this->api)()->getJson('/api/v1/einvoice/packages')->assertOk()->assertJsonCount(0, 'data.packages')->assertJsonPath('data.order', null);

    ($this->asAdmin)();
    $pkg = $this->postJson('/api/v1/admin/einvoice/packages', ['provider' => 'viettel', 'name' => '500 hóa đơn', 'invoices' => 500, 'price' => 600000, 'setup_fee' => null, 'signing_price' => 990000, 'featured' => true, 'active' => true])
        ->assertCreated()->assertJsonPath('data.setup_fee', '0.00')->json('data');

    $order = ($this->api)()->postJson('/api/v1/einvoice/orders', ['package' => $pkg['id'], 'signing' => true, 'company' => $this->company])->assertCreated()
        ->assertJsonPath('data.status', 'awaiting_payment')
        ->assertJsonPath('data.amount', '1590000.00')
        ->assertJsonPath('data.bank.configured', true)
        ->assertJsonPath('data.bank.memo', fn ($m) => str_starts_with($m, 'HDDT'))
        ->assertJsonPath('data.number', fn ($n) => preg_match('/^HDDT\d{4}-\d{5}$/', $n) === 1)
        ->json('data');
    ($this->api)()->postJson('/api/v1/einvoice/orders', ['package' => $pkg['id'], 'company' => $this->company])->assertStatus(422)->assertJsonValidationErrors('package');
    ($this->api)()->postJson('/api/v1/einvoice/orders', ['package' => $pkg['id'], 'company' => ['tax_code' => '123'] + $this->company])->assertStatus(422)->assertJsonValidationErrors('company.tax_code');

    ($this->asAdmin)();
    $this->getJson('/api/v1/admin/einvoice?status=open')->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.tenant.name', $this->tenant->name);
    $this->postJson("/api/v1/admin/einvoice/orders/{$order['id']}/advance", ['status' => 'account_created'])->assertStatus(422);
    $this->postJson("/api/v1/admin/einvoice/orders/{$order['id']}/payment", ['reference' => 'FT2611001'])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_ref', 'FT2611001');
    $this->postJson("/api/v1/admin/einvoice/orders/{$order['id']}/advance", ['status' => 'waiting_signature', 'shop_note' => 'Bấm Ký tờ khai', 'sign_url' => 'https://sinvoice.viettel.vn/ky'])->assertOk()
        ->assertJsonPath('data.status', 'waiting_signature');
    $this->postJson("/api/v1/admin/einvoice/orders/{$order['id']}/advance", ['status' => 'account_created'])->assertStatus(422);

    // The shop sees the step and can no longer cancel by itself.
    ($this->api)()->getJson('/api/v1/einvoice/packages')->assertJsonPath('data.order.status', 'waiting_signature')
        ->assertJsonPath('data.order.sign_url', 'https://sinvoice.viettel.vn/ky')->assertJsonPath('data.order.bank', null)->assertJsonMissingPath('data.order.payment_ref');
    ($this->api)()->postJson("/api/v1/einvoice/orders/{$order['id']}/cancel")->assertStatus(422);

    Http::fake(['api-vinvoice.viettel.vn/auth/login' => Http::response(['access_token' => 'vt-tok'])]);
    ($this->asAdmin)();
    $this->postJson("/api/v1/admin/einvoice/orders/{$order['id']}/connect", ['environment' => 'production', 'credentials' => [
        'tax_code' => '0312345678', 'username' => '0312345678', 'password' => 'p', 'template_code' => '1/001', 'invoice_series' => 'C25TMP', 'ignored' => 'x',
    ]])->assertOk()->assertJsonPath('data.status', 'connected');

    $account = EInvoiceAccount::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($account->provider)->toBe('viettel')->and($account->status)->toBe('connected');
    ($this->api)()->getJson('/api/v1/einvoice/packages')->assertJsonPath('data.order', null);
});

it('lets the shop cancel before paying and ask for another provider', function () {
    ($this->asAdmin)();
    $pkg = $this->postJson('/api/v1/admin/einvoice/packages', ['provider' => 'viettel', 'name' => '300', 'invoices' => 300, 'price' => 450000])->assertCreated()->json('data');
    $order = ($this->api)()->postJson('/api/v1/einvoice/orders', ['package' => $pkg['id'], 'signing' => true, 'company' => $this->company])->assertCreated()
        ->assertJsonPath('data.signing', false)->assertJsonPath('data.amount', '450000.00')->json('data');
    ($this->api)()->postJson("/api/v1/einvoice/orders/{$order['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    // A sold package is only switched off.
    ($this->asAdmin)();
    $this->deleteJson("/api/v1/admin/einvoice/packages/{$pkg['id']}")->assertOk();
    $this->getJson('/api/v1/admin/einvoice')->assertJsonPath('data.packages.0.active', false);

    ($this->api)()->postJson('/api/v1/einvoice/provider-requests', ['provider' => 'BKAV eHoadon'])->assertCreated();
    expect(EInvoiceProviderRequest::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    expect(EInvoiceOrder::withoutGlobalScopes()->count())->toBe(1);
});

it('keeps the sales pages to owners and super admins', function () {
    $staff = addMember($this->tenant, 'ORDER_STAFF');
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/einvoice/packages')->assertForbidden();
    ($this->api)()->getJson('/api/v1/admin/einvoice')->assertForbidden();
});
