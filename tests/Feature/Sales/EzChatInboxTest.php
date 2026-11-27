<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const INBOX_URL = 'https://app.ezchat.test';
const INBOX_SECRET = 'ezchat-shared-secret-0123456789abcdef';

beforeEach(function () {
    config(['marketplaces.ezchat.url' => INBOX_URL, 'marketplaces.ezchat.secret' => INBOX_SECRET]);
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('hides Tin nhắn until the business is joined to an EzChat workspace', function () {
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/ezchat-inbox')
        ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'not_linked')->assertJsonPath('data.unread', null);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/ezchat-inbox/open')->assertStatus(422)->assertJsonPath('reason', 'not_linked');
});

it('opens the inbox with a one-time ticket for this person, signed, and shows the unread badge', function () {
    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    $this->owner->forceFill(['ezchat_user_id' => 'usr_9'])->save();
    Http::fake([
        INBOX_URL.'/api/banhang/gw/inbox/ticket' => Http::response(['success' => true, 'data' => ['ticket' => 'tk_0123456789abcdefghij', 'appUrl' => INBOX_URL, 'expiresIn' => 300]]),
        INBOX_URL.'/api/banhang/gw/inbox/unread' => Http::response(['success' => true, 'data' => ['conversations' => 7, 'messages' => 12]]),
    ]);
    $api = actingInTenant($this->owner, $this->tenant);

    $api->getJson('/api/v1/ezchat-inbox')->assertJsonPath('data.available', true)->assertJsonPath('data.unread', 7);
    $url = $api->withHeader('Origin', 'https://erp.itso.test')->postJson('/api/v1/ezchat-inbox/open', ['conversation' => 'conv_1'])->assertOk()->json('data.url');
    expect($url)->toBe(INBOX_URL.'/erp?o='.urlencode('https://erp.itso.test').'#ticket=tk_0123456789abcdefghij&lang=vi&to='.urlencode('/inbox?c=conv_1'));

    Http::assertSent(function (Request $req) {
        $ts = $req->header('X-Banhang-Timestamp')[0];

        return $req->url() === INBOX_URL.'/api/banhang/gw/inbox/ticket'
            && $req->header('X-Banhang-Signature')[0] === hash_hmac('sha256', "{$ts}./api/banhang/gw/inbox/ticket.{$req->body()}", INBOX_SECRET)
            && $req['workspaceId'] === 'ten_1' && $req['userId'] === 'usr_9' && $req['email'] === $this->owner->email && $req['origin'] === 'https://erp.itso.test';
    });
});

it('explains when this person is not a member of the EzChat workspace', function () {
    $this->tenant->forceFill(['ezchat_workspace_id' => 'ten_1'])->save();
    Http::fake([INBOX_URL.'/*' => Http::response(['success' => false, 'error' => ['code' => 'MEMBER_NOT_FOUND', 'message' => 'x']], 404)]);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/ezchat-inbox/open')
        ->assertStatus(422)->assertJsonPath('reason', 'not_member');
    // The badge stays empty instead of failing the menu.
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/ezchat-inbox')->assertOk()->assertJsonPath('data.unread', null);
});

it('shows the customer of the open chat with their latest orders', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $customer = $api->postJson('/api/v1/customers', ['name' => 'Nguyễn Thị Hoa', 'phone' => '0909 123 128'])->assertCreated()->json('data.id');
    $variant = makeVariant($this->tenant, 'TAI-X3');
    stockIn($this->tenant, $variant, 5);
    $order = createSalesOrder($this->owner, $this->tenant, [[$variant, 1, 690000]], ['customer_id' => $customer])->assertCreated()->json('data.id');

    // The chat's number in another form (+84) still finds her.
    $r = $api->getJson('/api/v1/ezchat-inbox/customer?phone='.urlencode('+84 909 123 128'))->assertOk();
    expect($r->json('data.customer.id'))->toBe($customer)
        ->and($r->json('data.customer.orders_count'))->toBe(1)
        ->and($r->json('data.orders.0.id'))->toBe($order);

    $api->getJson('/api/v1/ezchat-inbox/customer?phone=0911000000')->assertOk()->assertJsonPath('data.customer', null)->assertJsonPath('data.orders', []);
});
