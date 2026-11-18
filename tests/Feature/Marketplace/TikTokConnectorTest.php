<?php

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\WebhookEvent;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('lists TikTok Shop, connectable only once the EzChat link is configured', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/marketplaces')->assertOk()
        ->assertJsonPath('data.1.key', 'tiktok')->assertJsonPath('data.1.available', true)
        ->assertJsonPath('data.1.credentials_configured', false);
    $api->postJson('/api/v1/marketplace-accounts/authorize/tiktok')->assertStatus(422)->assertJsonPath('code', 'CREDENTIALS_MISSING');

    expect(MarketplaceAccount::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects TikTok Shop pushes not signed by EzChat, even with a TikTok-looking signature header', function () {
    $body = json_encode(['type' => 1, 'shop_id' => '7000', 'data' => ['order_id' => '576']]);
    $this->call('POST', '/api/v1/webhooks/tiktok', [], [], [], ['HTTP_AUTHORIZATION' => hash_hmac('sha256', 'key'.$body, 'secret'), 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);

    expect(WebhookEvent::query()->where('marketplace', 'tiktok')->value('status'))->toBe(WebhookEvent::REJECTED);
});
