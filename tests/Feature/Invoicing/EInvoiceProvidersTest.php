<?php

use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Support\VndWords;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
    stockIn($this->tenant, $this->shirt, 50);
    $this->api = fn () => actingInTenant($this->owner, $this->tenant);
});

it('talks to Viettel S-Invoice: login cookie, create, PDF', function () {
    Http::fake([
        'api-vinvoice.viettel.vn/auth/login' => Http::response(['access_token' => 'vt-tok']),
        'api-vinvoice.viettel.vn/services/einvoiceapplication/api/InvoiceAPI/InvoiceWS/createInvoice/0312345678' => Http::response(['errorCode' => null, 'result' => ['invoiceNo' => 'C25TMP15', 'transactionID' => 'x']]),
        'api-vinvoice.viettel.vn/services/einvoiceapplication/api/InvoiceAPI/InvoiceUtilsWS/getInvoiceRepresentationFile' => Http::response(['errorCode' => null, 'fileToBytes' => base64_encode('%PDF-1.4 viettel')]),
    ]);

    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'viettel', 'environment' => 'production', 'credentials' => [
        'tax_code' => '0312345678', 'username' => '0312345678', 'password' => 'p@ss', 'template_code' => '1/001', 'invoice_series' => 'c25tmp',
    ]])->assertOk()->assertJsonPath('data.template', '1/001|C25TMP');

    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 2, 110000]])->json('data.id');
    $invoice = ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertCreated()
        ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.invoice_number', 'C25TMP15')
        ->assertJsonPath('data.pdf_download', true)->json('data');

    Http::assertSent(function (Request $r) {
        if (! str_contains($r->url(), 'createInvoice')) {
            return false;
        }
        $body = $r->data();

        return $r->header('Cookie')[0] === 'access_token=vt-tok'
            && $body['generalInvoiceInfo']['templateCode'] === '1/001'
            && $body['generalInvoiceInfo']['invoiceSeries'] === 'C25TMP'
            && count($body['itemInfo']) === 1
            && (float) $body['summarizeInfo']['totalAmountWithTax'] === 220000.0;
    });

    $pdf = ($this->api)()->get("/api/v1/einvoices/{$invoice['id']}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and($pdf->getContent())->toBe('%PDF-1.4 viettel');
});

it('reports Viettel refusing the API account', function () {
    Http::fake(['api-vinvoice.viettel.vn/auth/login' => Http::response(['error' => 'invalid'], 401)]);

    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'viettel', 'environment' => 'production', 'credentials' => [
        'tax_code' => '0312345678', 'username' => 'u', 'password' => 'bad', 'template_code' => '1/001', 'invoice_series' => 'C25TMP',
    ]])->assertStatus(422)->assertJsonPath('errors.credentials.0', fn ($m) => str_contains($m, 'Viettel không nhận'));
});

it('talks to VNPT Invoice over SOAP on the shop portal', function () {
    $soap = fn (string $method, string $result) => Http::response("<?xml version=\"1.0\"?><soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\"><soap:Body><{$method}Response xmlns=\"http://tempuri.org/\"><{$method}Result>{$result}</{$method}Result></{$method}Response></soap:Body></soap:Envelope>", 200, ['Content-Type' => 'text/xml']);
    Http::fake(function (Request $r) use ($soap) {
        $action = $r->header('SOAPAction')[0] ?? '';

        return match (true) {
            str_contains($action, 'ImportAndPublishInv') => $soap('ImportAndPublishInv', 'OK:1/001;C25TAA-'.'EZ_42'),
            str_contains($action, 'downloadInvPDFFkeyNoPay') => $soap('downloadInvPDFFkeyNoPay', str_contains($r->body(), 'EZKIEMTRAKETNOI') ? 'ERR:6' : base64_encode('%PDF vnpt')),
            default => Http::response('', 500),
        };
    });

    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'vnpt', 'environment' => 'production', 'credentials' => [
        'portal' => 'https://0312345678-tt78admin.vnpt-invoice.com.vn/', 'service_user' => 'svc', 'service_password' => 'sp', 'admin_user' => 'adm', 'admin_password' => 'ap', 'pattern' => '1/001', 'serial' => 'C25TAA',
    ]])->assertOk()->assertJsonPath('data.template', '1/001|C25TAA');

    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1, 250000]])->json('data.id');
    $invoice = ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertCreated()
        ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.invoice_number', '42')->json('data');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '0312345678-tt78admin.vnpt-invoice.com.vn/PublishService.asmx')
        && str_contains($r->body(), '&lt;Invoices&gt;') && str_contains($r->body(), 'Hai trăm năm mươi nghìn đồng'));

    expect(($this->api)()->get("/api/v1/einvoices/{$invoice['id']}/pdf")->assertOk()->getContent())->toBe('%PDF vnpt');
});

it('rejects a VNPT portal that is not VNPT', function () {
    Http::fake();
    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'vnpt', 'environment' => 'production', 'credentials' => [
        'portal' => 'evil.example.com', 'service_user' => 'svc', 'service_password' => 'sp', 'admin_user' => 'adm', 'admin_password' => 'ap', 'pattern' => '1/001', 'serial' => 'C25TAA',
    ]])->assertStatus(422);
    Http::assertNothingSent();
});

it('creates MISA drafts the shop signs in meInvoice, then picks up the number', function () {
    config(['einvoice.misa.app_id' => 'app-1']);
    $status = ['InvNo' => null];
    Http::fake(function (Request $r) use (&$status) {
        return match (true) {
            str_ends_with($r->url(), 'auth/token') => Http::response(['Success' => true, 'Data' => 'misa-tok']),
            str_contains($r->url(), 'InvoicePublishing/templates') => Http::response(['Success' => true, 'Data' => json_encode([['InvSeries' => '1C25TMP', 'InvTemplateNo' => '1', 'Inactive' => false]])]),
            str_contains($r->url(), 'createinvoice') => Http::response(['Success' => true, 'Data' => json_encode([['RefID' => $r->data()[0]['RefID'], 'ErrorCode' => null]])]),
            str_contains($r->url(), 'invoice-status/refid') => Http::response(['Success' => true, 'Data' => [['RefID' => $r->data()[0], 'TransactionID' => 'T-9'] + $status]]),
            str_contains($r->url(), 'downloadinvoice') => Http::response(['Success' => true, 'Data' => [['TransactionID' => 'T-9', 'Data' => base64_encode('%PDF misa')]]]),
            default => Http::response([], 404),
        };
    });

    ($this->api)()->getJson('/api/v1/einvoice/settings')->assertJson(fn ($json) => $json->where('data.providers', fn ($p) => collect($p)->firstWhere('provider', 'misa')['available'] === true)->etc());
    ($this->api)()->postJson('/api/v1/einvoice/account', ['provider' => 'misa', 'environment' => 'production', 'credentials' => ['tax_code' => '0312345678', 'username' => 'u', 'password' => 'p']])
        ->assertOk()->assertJsonPath('data.template', '1|1C25TMP');

    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1, 100000]])->json('data.id');
    $invoice = ($this->api)()->postJson("/api/v1/orders/{$id}/einvoice")->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.message', fn ($m) => str_contains($m, 'meinvoice.vn'))->json('data');

    $status = ['InvNo' => '00000007', 'InvDate' => '2026-11-28T10:00:00', 'EInvoiceStatus' => 1];
    $model = EInvoice::withoutGlobalScopes()->where('ulid', $invoice['id'])->firstOrFail();
    ($this->api)()->postJson("/api/v1/einvoices/{$invoice['id']}/refresh")->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.invoice_number', '00000007');
    expect(($this->api)()->get("/api/v1/einvoices/{$model->ulid}/pdf")->assertOk()->getContent())->toBe('%PDF misa');
});

it('spells amounts in Vietnamese words', function () {
    expect(VndWords::spell(0))->toBe('Không đồng')
        ->and(VndWords::spell(1250000))->toBe('Một triệu hai trăm năm mươi nghìn đồng')
        ->and(VndWords::spell(105))->toBe('Một trăm lẻ năm đồng')
        ->and(VndWords::spell(21))->toBe('Hai mươi mốt đồng')
        ->and(VndWords::spell(15))->toBe('Mười lăm đồng')
        ->and(VndWords::spell(1000000000))->toBe('Một tỷ đồng')
        ->and(VndWords::spell(2005000))->toBe('Hai triệu không trăm lẻ năm nghìn đồng');
});
