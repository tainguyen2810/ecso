<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const AI_URL = 'https://app.ezchat.test';
const AI_SECRET = 'ezchat-shared-secret-0123456789abcdef';

beforeEach(function () {
    config(['marketplaces.ezchat.url' => AI_URL, 'marketplaces.ezchat.secret' => AI_SECRET]);
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function aiAnswer(array $data, int $credits = 2): void
{
    Http::fake([AI_URL.'/api/banhang/gw/ai' => Http::response(['success' => true, 'data' => ['task' => 'x', 'data' => $data, 'credits' => $credits, 'images' => 1]])]);
}

it('says AI needs an EzChat workspace until one is joined', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/assistant/status')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'not_linked');
    $api->postJson('/api/v1/assistant/invoice', ['images' => ['aGVsbG8gd29ybGQ=']])->assertStatus(409)->assertJsonPath('reason', 'not_linked');

    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    $api->getJson('/api/v1/assistant/status')->assertJsonPath('data.available', true);
});

it('reads a supplier invoice into a purchase draft with matched lines and supplier', function () {
    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    $wifi = makeVariant($this->tenant, 'AX210-PCIE', ['product_name' => 'Card wifi AX210 PCIe']);
    $ram = makeVariant($this->tenant, 'KVR32N22S8-8', ['product_name' => 'RAM Kingston 8GB DDR4 3200']);
    $api = actingInTenant($this->owner, $this->tenant);
    $supplier = $api->postJson('/api/v1/suppliers', ['name' => 'Công ty TNHH Linh kiện Hoàng Gia', 'tax_code' => '0316789123'])->json('data.id');

    aiAnswer([
        'supplier' => ['name' => 'CÔNG TY TNHH LINH KIỆN HOÀNG GIA', 'tax_code' => '0316 789 123'],
        'invoice' => ['number' => '002871', 'series' => 'C26TLH', 'date' => '2026-11-20'],
        'lines' => [
            ['name' => 'Card wifi Intel AX210 PCIe', 'code' => 'AX210 PCIE', 'unit' => 'Cái', 'quantity' => 10, 'unit_price' => 385000, 'amount' => 3850000],
            ['name' => 'RAM DDR4 8GB 3200 Kingston', 'code' => '', 'unit' => 'Thanh', 'quantity' => '12', 'unit_price' => '420.000', 'amount' => 0],
            ['name' => 'Chuột không dây Logitech M331', 'code' => 'M331', 'unit' => 'Cái', 'quantity' => 15, 'unit_price' => 265000, 'amount' => 3975000],
        ],
        'discount' => 105000, 'shipping' => 0, 'vat_amount' => 0, 'total' => 12760000,
    ], 3);

    $r = $api->postJson('/api/v1/assistant/invoice', ['images' => ['data:image/jpeg;base64,aGVsbG8gd29ybGQ=']])->assertOk();
    expect($r->json('data.credits'))->toBe(3)
        ->and($r->json('data.draft.supplier.match.id'))->toBe($supplier)
        ->and($r->json('data.draft.invoice.number'))->toBe('002871')
        ->and($r->json('data.draft.lines.0.status'))->toBe('matched')
        ->and($r->json('data.draft.lines.0.variant.id'))->toBe($wifi->ulid)
        ->and($r->json('data.draft.lines.1.variant.id'))->toBe($ram->ulid)
        ->and($r->json('data.draft.lines.1.unit_price'))->toEqual(420000)
        ->and($r->json('data.draft.lines.1.amount'))->toEqual(5040000)
        ->and($r->json('data.draft.lines.2.status'))->toBe('new')
        ->and($r->json('data.draft.total_matches'))->toBeTrue();

    Http::assertSent(function (Request $req) {
        $body = $req->body();
        $ts = $req->header('X-Banhang-Timestamp')[0];

        return $req->url() === AI_URL.'/api/banhang/gw/ai'
            && $req->header('X-Banhang-Signature')[0] === hash_hmac('sha256', "{$ts}./api/banhang/gw/ai.{$body}", AI_SECRET)
            && $req['workspaceId'] === 'ten_1' && $req['task'] === 'invoice' && $req['images'][0]['data'] === 'aGVsbG8gd29ybGQ=';
    });
});

it('flags a total that does not add up', function () {
    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    aiAnswer(['lines' => [['name' => 'A', 'quantity' => 2, 'unit_price' => 100000]], 'total' => 900000]);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/assistant/invoice', ['images' => ['aGVsbG8gd29ybGQ=']])
        ->assertOk()->assertJsonPath('data.draft.total_matches', false)->assertJsonPath('data.draft.lines.0.amount', 200000);
});

it('turns EzChat errors into messages for the shop', function () {
    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    Http::fake([AI_URL.'/*' => Http::response(['error' => ['code' => 'AI_CREDIT_OUT', 'message' => 'x']], 402)]);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/assistant/invoice', ['images' => ['aGVsbG8gd29ybGQ=']])
        ->assertStatus(422)->assertJsonPath('reason', 'AI_CREDIT_OUT')->assertJsonPath('message', fn ($m) => str_contains($m, 'hết credit'));
});

it('suggests products from photos with the shop category, a free SKU and a price from the cost', function () {
    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    makeVariant($this->tenant, 'AX23', ['product_name' => 'Bộ phát cũ']);
    $api = actingInTenant($this->owner, $this->tenant);
    $parent = $api->postJson('/api/v1/categories', ['name' => 'Thiết bị mạng'])->json('data.id');
    $cat = $api->postJson('/api/v1/categories', ['name' => 'Router wifi', 'parent_id' => $parent])->json('data.id');
    $brand = $api->postJson('/api/v1/brands', ['name' => 'TP-Link'])->json('data.id');

    aiAnswer(['products' => [[
        'images' => [1, 2, 9], 'name' => 'Router WiFi 6 TP-Link Archer AX23', 'category' => 'Thiết bị mạng › Router wifi', 'brand' => 'tp-link',
        'sku' => 'AX23', 'warranty_months' => 24, 'weight_grams' => 650, 'cost_price' => 820000,
        'variants' => [['name' => 'Màu', 'values' => ['Đen', 'Trắng']]], 'description' => 'Router WiFi 6.', 'seen' => 'Archer AX23', 'missing' => '',
    ]]]);

    $r = $api->postJson('/api/v1/assistant/products', ['images' => ['aGVsbG8gd29ybGQ=', 'aGVsbG8gd29ybGQ='], 'markup' => 25])->assertOk();
    expect($r->json('data.products.0.category.id'))->toBe($cat)
        ->and($r->json('data.products.0.brand.id'))->toBe($brand)
        ->and($r->json('data.products.0.sku'))->toBe('AX23-2')
        ->and($r->json('data.products.0.price'))->toEqual(1030000)
        ->and($r->json('data.products.0.images'))->toBe([1, 2]);
    Http::assertSent(fn (Request $req) => $req['task'] === 'product' && in_array('Thiết bị mạng › Router wifi', $req['categories'], true));
});

it('keeps AI to members who may buy stock or create products', function () {
    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    $staff = addMember($this->tenant, 'ORDER_STAFF');
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/assistant/invoice', ['images' => ['aGVsbG8gd29ybGQ=']])->assertForbidden();
});
