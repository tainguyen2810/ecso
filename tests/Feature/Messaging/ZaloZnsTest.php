<?php

use App\Modules\Messaging\Models\ZaloAccount;
use App\Modules\Messaging\Models\ZaloMessage;
use App\Modules\Messaging\Zalo\ZaloService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/** Zalo ZNS (C6) against Zalo's APIs simulated with Http::fake: nothing leaves the test. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->variant = makeVariant($this->tenant, 'ATC-TRANG-M');
    stockIn($this->tenant, $this->variant, 20);
    $this->refreshes = 0;
    $this->sent = [];
    Http::preventStrayRequests();
    Http::fake([
        'oauth.zaloapp.com/*' => function (Request $r) {
            if ($r->header('secret_key')[0] !== 'sk-1' || ! str_starts_with($r['refresh_token'], 'rt-')) {
                return Http::response(['error' => -14014, 'error_name' => 'Invalid refresh token']);
            }
            $this->refreshes++;

            return Http::response(['access_token' => 'at-'.$this->refreshes, 'refresh_token' => 'rt-'.($this->refreshes + 100), 'expires_in' => '90000']);
        },
        'openapi.zalo.me/v2.0/oa/getoa' => Http::response(['error' => 0, 'data' => ['oa_id' => '123', 'name' => 'Cửa hàng Mẫu']]),
        'business.openapi.zalo.me/template/all*' => Http::response(['error' => 0, 'data' => [
            ['templateId' => 345612, 'templateName' => 'Xác nhận đơn hàng', 'status' => 'ENABLE'],
            ['templateId' => 345620, 'templateName' => 'Cảm ơn', 'status' => 'PENDING_REVIEW'],
        ]]),
        'business.openapi.zalo.me/template/info/v2*' => Http::response(['error' => 0, 'data' => ['listParams' => [
            ['name' => 'customer_name', 'require' => true, 'type' => 'STRING', 'maxLength' => 30],
            ['name' => 'order_code', 'require' => true, 'type' => 'STRING', 'maxLength' => 30],
            ['name' => 'total', 'require' => false, 'type' => 'NUMBER'],
        ]]]),
        'business.openapi.zalo.me/message/template' => function (Request $r) {
            if ($r->header('access_token')[0] === 'at-1' && count($this->sent) === 1) {
                return Http::response(['error' => -124, 'message' => 'Access token is invalid']); // expired: refreshed once
            }
            if ($r['phone'] === '84900000000') {
                return Http::response(['error' => -118, 'message' => 'Zalo account not existed']);
            }
            $this->sent[] = $r->data();

            return Http::response(['error' => 0, 'message' => 'Success', 'data' => ['msg_id' => 'm'.count($this->sent)]]);
        },
    ]);
    $this->connect = fn (array $override = []) => $this->api->postJson('/api/v1/zalo/account', ['app_id' => '4501234567', 'secret_key' => 'sk-1', 'refresh_token' => 'rt-0', ...$override]);
    // The default channel is the counter (POS), which never sends ZNS: orders here come from a fanpage.
    $this->channel = $this->api->postJson('/api/v1/sales-channels', ['name' => 'Fanpage', 'type' => 'facebook', 'warehouse_id' => defaultWarehouse($this->tenant)->ulid])->assertCreated()->json('data.id');
    $this->order = fn (string $phone = '0938123321', array $extra = []) => createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 150000]], [
        'stage' => 'pending', 'channel_id' => $this->channel, 'buyer_name' => 'Trần Thị Mai', 'buyer_phone' => $phone, ...$extra,
    ])->assertCreated()->json('data.id');
});

it('connects the OA, reads the approved templates with their parameters and never shows the keys', function () {
    ($this->connect)(['secret_key' => 'wrong'])->assertStatus(422)->assertJsonPath('errors.refresh_token.0', fn ($m) => str_contains($m, 'Zalo không nhận'));
    $data = ($this->connect)()->assertOk()->json('data');
    expect($data['account']['oa_name'])->toBe('Cửa hàng Mẫu')->and($data['account']['templates'])->toHaveCount(2)
        ->and($data['account']['templates'][0]['params'][0]['name'])->toBe('customer_name')
        ->and($data['account']['templates'][1]['params'])->toBe([]);
    expect(json_encode($this->api->getJson('/api/v1/zalo')->json()))->not->toContain('sk-1')->not->toContain('rt-101')->not->toContain('at-1');
    // The refresh token Zalo handed back replaces the one typed (a refresh token works once).
    expect(ZaloAccount::withoutGlobalScopes()->sole()->secrets['refresh_token'])->toBe('rt-101');

    $staff = addMember($this->tenant, 'ORDER_STAFF');
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/zalo')->assertForbidden();
});

it('sends the chosen template when an order is confirmed, once, and logs failures in words', function () {
    ($this->connect)();
    $this->api->putJson('/api/v1/zalo/rules', ['rules' => ['confirmed' => ['template_id' => '345620', 'enabled' => true]]])->assertStatus(422);
    $this->api->putJson('/api/v1/zalo/rules', ['rules' => ['confirmed' => ['template_id' => '345612', 'enabled' => true, 'params' => ['total' => 'total']]]])
        ->assertOk()->assertJsonPath('data.account.rules.confirmed.params', ['customer_name' => 'customer_name', 'order_code' => 'order_code', 'total' => 'total']);

    $id = ($this->order)();
    expect($this->sent)->toBe([]); // still waiting for confirmation
    $this->api->postJson("/api/v1/orders/{$id}/actions/confirm")->assertOk();
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    expect($this->sent)->toHaveCount(1)->and($this->sent[0]['phone'])->toBe('84938123321')
        ->and($this->sent[0]['template_data'])->toEqual(['customer_name' => 'Trần Thị Mai', 'order_code' => $order->external_order_id, 'total' => '300.000']);

    // Sending again for the same order and event does nothing; an expired token is refreshed once.
    expect(TenantContext::run($this->tenant, fn () => app(ZaloService::class)->send($order, 'confirmed')))->toBeNull();
    $second = ($this->order)();
    $this->api->postJson("/api/v1/orders/{$second}/actions/confirm")->assertOk();
    expect($this->sent)->toHaveCount(2)->and($this->refreshes)->toBe(2);

    $bad = ($this->order)('0900000000');
    $this->api->postJson("/api/v1/orders/{$bad}/actions/confirm")->assertOk();
    $log = $this->api->getJson('/api/v1/zalo')->json('data.messages');
    expect($log[0]['status'])->toBe('failed')->and($log[0]['error'])->toBe('Số điện thoại chưa dùng Zalo.')->and($log[0]['phone'])->toBe('8490***000');
    expect(ZaloMessage::withoutGlobalScopes()->where('status', 'sent')->count())->toBe(2);
});

it('reminds buyers who still owe money after three days, once', function () {
    ($this->connect)();
    $this->api->putJson('/api/v1/zalo/rules', ['rules' => ['payment_reminder' => ['template_id' => '345612', 'enabled' => true]]])->assertOk();
    $old = ($this->order)();
    $fresh = ($this->order)('0912345678');
    Order::withoutGlobalScopes()->where('ulid', $old)->update(['placed_at' => now()->subDays(5)]);

    Artisan::call('ecso:zalo:payment-reminders');
    Artisan::call('ecso:zalo:payment-reminders');
    expect($this->sent)->toHaveCount(1)->and($this->sent[0]['phone'])->toBe('84938123321');
    expect(Order::withoutGlobalScopes()->where('ulid', $fresh)->value('id'))->not->toBeNull();
});

it('reads Vietnamese mobile numbers', function () {
    expect(ZaloService::phone('0938 123 321'))->toBe('84938123321')
        ->and(ZaloService::phone('+84 912-345-678'))->toBe('84912345678')
        ->and(ZaloService::phone('028 3823 4567'))->toBeNull()
        ->and(ZaloService::phone(''))->toBeNull();
});
