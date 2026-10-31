<?php

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\TikTok\TikTokConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\WebhookEvent;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('registers TikTok Shop as a skeleton whose capabilities are all UNKNOWN', function () {
    $connector = app(ConnectorRegistry::class)->for('tiktok');

    expect($connector)->toBeInstanceOf(TikTokConnector::class)
        ->and(array_unique(array_values($connector->capabilities())))->toBe([Capability::UNKNOWN]);

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/marketplaces')->assertOk()
        ->assertJsonPath('data.1.key', 'tiktok')->assertJsonPath('data.1.available', true)
        ->assertJsonPath('data.1.credentials_configured', false);
});

it('refuses to connect TikTok Shop without credentials, and reports UNKNOWN once they exist', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->postJson('/api/v1/marketplace-accounts/authorize/tiktok')->assertStatus(422)->assertJsonPath('code', 'CREDENTIALS_MISSING');

    config(['marketplaces.credentials.tiktok' => ['app_key' => 'k', 'app_secret' => 's']]);
    $api->postJson('/api/v1/marketplace-accounts/authorize/tiktok')->assertStatus(422)
        ->assertJsonPath('code', 'UNSUPPORTED_CAPABILITY')->assertJsonPath('state', 'unknown');

    expect(MarketplaceAccount::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects TikTok Shop webhooks even with a plausible-looking signature header', function () {
    $body = json_encode(['type' => 1, 'shop_id' => '7000', 'data' => ['order_id' => '576']]);
    $this->call('POST', '/api/v1/webhooks/tiktok', [], [], [], ['HTTP_AUTHORIZATION' => hash_hmac('sha256', 'key'.$body, 'secret'), 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);

    expect(WebhookEvent::query()->where('marketplace', 'tiktok')->value('status'))->toBe(WebhookEvent::REJECTED);
});
