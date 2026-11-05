<?php

use App\Modules\Marketplace\Domain\SyncErrorCode;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\SyncRun;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeWooStore;

/** Sync Center round 1 on a WooCommerce store: precise error codes and refused keys during order sync. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->mouse = makeVariant($this->tenant, 'G304-BK', ['product_name' => 'Chuột G304', 'ref_price' => 499000]);
    stockIn($this->tenant, $this->mouse, 12);
    $this->store = new FakeWooStore;
    $this->store->addSimple(11, 'G304-BK', 'Chuột Logitech G304', '549000', '499000', stock: 40);
    config(['app.url' => 'https://erp.example.vn']);
    $this->account = wooConnect($this);
    wooMap($this, $this->account);
    $this->api = actingInTenant($this->owner, $this->tenant);
});

it('codes a busy store as api_unavailable on the order error and the poll', function () {
    $this->store->addOrder(904, 'processing', [[11, 1, 'G304-BK', 499000]]);
    $this->store->failOrders = [904];

    Artisan::call('ecso:orders:reconcile');

    expect(SyncError::withoutGlobalScopes()->where('external_id', '904')->value('error_code'))->toBe(SyncErrorCode::API_UNAVAILABLE)
        ->and(SyncRun::withoutGlobalScopes()->where('marketplace_account_id', $this->account->id)->value('error_code'))->toBe(SyncErrorCode::API_UNAVAILABLE);
    $this->api->getJson('/api/v1/sync-center/tasks?type=order_poll&status=failed')->assertOk()->assertJsonPath('data.0.error_code', 'api_unavailable');
});

it('marks the store disconnected when its keys are refused while reading orders', function () {
    $this->store->addOrder(905, 'processing', [[11, 1, 'G304-BK', 499000]]);
    $this->store->key = 'ck_other';

    Artisan::call('ecso:orders:reconcile');

    expect($this->account->refresh()->status)->toBe(MarketplaceAccount::EXPIRED)
        ->and(SyncRun::withoutGlobalScopes()->where('marketplace_account_id', $this->account->id)->value('error_code'))->toBe(SyncErrorCode::TOKEN_EXPIRED);
    $this->api->getJson('/api/v1/sync-center/overview')->assertOk()->assertJsonPath('data.accounts.disconnected', 1);
});
