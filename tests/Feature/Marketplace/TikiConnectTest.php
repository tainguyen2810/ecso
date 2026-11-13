<?php

use App\Modules\Marketplace\Connectors\Tiki\TikiClient;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Kết nối Tiki (C8): the shop's own In-house app keys, checked against a faked Tiki Open API. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
});

it('connects a Tiki shop with the app keys: token by client credentials, shop from sellers/me', function () {
    Http::fake([
        TikiClient::TOKEN_URL => Http::response(['access_token' => 'tk-live-1', 'expires_in' => 3600, 'token_type' => 'bearer']),
        TikiClient::BASE_URL.'sellers/me' => Http::response(['sid' => '8624DAE31E9AECF54C552D16A1D292CE28338587', 'name' => 'Zero Shop', 'active' => 1, 'operation_models' => ['dropship']]),
    ]);

    $this->api->postJson('/api/v1/marketplace-accounts/tiki/connect', ['client_id' => '7590139168389961', 'client_secret' => 'tiki-secret-77'])
        ->assertCreated()->assertJsonPath('data.shop_name', 'Zero Shop')->assertJsonMissing(['client_secret' => 'tiki-secret-77']);

    Http::assertSent(fn (Request $r) => $r->url() === TikiClient::TOKEN_URL && $r['grant_type'] === 'client_credentials'
        && $r->header('Authorization')[0] === 'Basic '.base64_encode('7590139168389961:tiki-secret-77'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'sellers/me') && $r->header('Authorization')[0] === 'Bearer tk-live-1');

    $account = MarketplaceAccount::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
    $credential = MarketplaceCredential::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->sole();
    expect($account->marketplace)->toBe('tiki')->and($account->external_shop_id)->toBe('8624DAE31E9AECF54C552D16A1D292CE28338587')
        ->and($account->status)->toBe(MarketplaceAccount::CONNECTED)->and($account->sync_enabled)->toBeFalse()
        ->and($account->settings['client_id'])->toBe('7590139168389961')
        ->and($credential->refresh_token)->toBe('tiki-secret-77')->and($credential->access_token)->toBe('tk-live-1');
});

it('says plainly when Tiki refuses the keys, and saves nothing', function () {
    Http::fake([TikiClient::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

    $this->api->postJson('/api/v1/marketplace-accounts/tiki/connect', ['client_id' => 'x', 'client_secret' => 'y'])
        ->assertStatus(422)->assertJsonPath('errors.client_id.0', 'Client ID hoặc Client Secret không đúng, hoặc ứng dụng chưa được kết nối với shop trên Tiki.');
    expect(MarketplaceAccount::withoutGlobalScopes()->count())->toBe(0);
});

it('needs the permission to connect shops', function () {
    $staff = addMember($this->tenant, 'ORDER_STAFF');
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/marketplace-accounts/tiki/connect', ['client_id' => 'x', 'client_secret' => 'y'])->assertForbidden();
});
