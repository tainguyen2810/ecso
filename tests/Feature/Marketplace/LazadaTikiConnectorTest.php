<?php

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Lazada\LazadaConnector;
use App\Modules\Marketplace\Connectors\Tiki\TikiConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\WebhookEvent;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('registers Lazada and Tiki as skeletons whose capabilities are all UNKNOWN', function (string $key, string $class) {
    $connector = app(ConnectorRegistry::class)->for($key);

    expect($connector)->toBeInstanceOf($class)
        ->and(array_unique(array_values($connector->capabilities())))->toBe([Capability::UNKNOWN])
        // Stock update is UNKNOWN, not claimed as supported nor as unsupported, until the docs are read.
        ->and($connector->capabilities()[Capability::INVENTORY_UPDATE])->toBe(Capability::UNKNOWN);

    $row = collect(actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/marketplaces')->assertOk()->json('data'))->firstWhere('key', $key);
    expect($row['available'])->toBeTrue()->and($row['credentials_configured'])->toBeFalse();
})->with([['lazada', LazadaConnector::class], ['tiki', TikiConnector::class]]);

it('refuses to connect without credentials, and reports UNKNOWN once they exist', function (string $key, array $credentials) {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->postJson("/api/v1/marketplace-accounts/authorize/{$key}")->assertStatus(422)->assertJsonPath('code', 'CREDENTIALS_MISSING');

    config(["marketplaces.credentials.{$key}" => $credentials]);
    $api->postJson("/api/v1/marketplace-accounts/authorize/{$key}")->assertStatus(422)
        ->assertJsonPath('code', 'UNSUPPORTED_CAPABILITY')->assertJsonPath('state', 'unknown');

    expect(MarketplaceAccount::withoutGlobalScopes()->count())->toBe(0);
})->with([
    ['lazada', ['app_key' => 'k', 'app_secret' => 's']],
    ['tiki', ['client_id' => 'c', 'client_secret' => 's']],
]);

it('rejects Lazada and Tiki webhooks and keeps them for inspection', function (string $key) {
    $this->postJson("/api/v1/webhooks/{$key}", ['seller_id' => '1', 'data' => ['order_id' => '9']])->assertStatus(401);

    expect(WebhookEvent::query()->where('marketplace', $key)->value('status'))->toBe(WebhookEvent::REJECTED);
})->with(['lazada', 'tiki']);
