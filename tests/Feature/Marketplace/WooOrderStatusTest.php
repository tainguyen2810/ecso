<?php

use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeWooStore;

/** WooCommerce orders, round C: the store's own status map, and one-way ("chỉ website → ECSO") stores. */
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
    $this->bump = function (int $id, array $changes) {
        $this->travel(1)->minutes();
        $this->store->orders[$id] = array_merge($this->store->orders[$id], $changes, ['date_modified_gmt' => now()->utc()->format('Y-m-d\TH:i:s')]);

        return $this->store->orders[$id];
    };
});

function statusOf(string $id): string
{
    return Order::withoutGlobalScopes()->where('external_order_id', $id)->sole()->status;
}

it('maps statuses a plugin added, per store, and moves the orders that were waiting on them', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(950, 'dang-giao', [[11, 1, 'G304-BK', 499000]]))->assertOk();
    expect(statusOf('950'))->toBe('UNKNOWN_STATUS');

    $url = "/api/v1/marketplace-accounts/{$this->account->ulid}/status-map";
    $this->api->getJson($url)->assertOk()->assertJsonPath('data.defaults.processing', 'READY_TO_PACK')
        ->assertJsonPath('data.seen.0.raw_status', 'dang-giao')->assertJsonPath('data.seen.0.unknown', 1);
    $this->api->putJson($url, ['map' => ['Đang Giao' => 'IN_TRANSIT']])->assertUnprocessable();
    $this->api->putJson($url, ['map' => ['dang-giao' => 'UNKNOWN_STATUS']])->assertUnprocessable();

    $this->api->putJson($url, ['map' => ['dang-giao' => 'IN_TRANSIT']])->assertOk()
        ->assertJsonPath('data.custom.dang-giao', 'IN_TRANSIT')->assertJsonPath('data.orders_moved', 1);
    $b = balanceOf(defaultWarehouse($this->tenant), $this->mouse);
    expect(statusOf('950'))->toBe('IN_TRANSIT')->and([$b->on_hand, $b->reserved])->toBe([11, 0]);

    // New orders with that status follow the map, and the reconcile asks Woo for that status too.
    wooWebhook($this, 'order.created', $this->store->addOrder(951, 'dang-giao', [[11, 1, 'G304-BK', 499000]]))->assertOk();
    expect(statusOf('951'))->toBe('IN_TRANSIT');
    Artisan::call('ecso:orders:reconcile');
    $listed = collect($this->store->calls)->last(fn ($c) => $c['path'] === 'orders');
    expect($listed['body']['status'] ?? '')->toContain('dang-giao');

    // An entry set to null is removed.
    $this->api->putJson($url, ['map' => ['dang-giao' => null]])->assertOk()->assertJsonPath('data.custom', []);
});

it('keeps order actions in ECSO for a one-way store and does not fight the website status', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(952, 'processing', [[11, 2, 'G304-BK', 499000]]))->assertOk();
    $this->api->patchJson("/api/v1/marketplace-accounts/{$this->account->ulid}", ['settings' => ['push_status' => false]])->assertOk()
        ->assertJsonPath('data.settings.push_status', false);
    $order = Order::withoutGlobalScopes()->where('external_order_id', '952')->sole();
    $writes = fn () => collect($this->store->calls)->filter(fn ($c) => $c['method'] === 'PUT' && str_starts_with($c['path'], 'orders/'))->count();

    $this->api->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertOk();
    $this->api->postJson("/api/v1/orders/{$order->ulid}/actions/handover")->assertOk();
    expect(statusOf('952'))->toBe('HANDED_OVER')->and($writes())->toBe(0)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->mouse)->on_hand)->toBe(10);

    // The website still says "processing": ECSO's status stays, no conflict is raised.
    wooWebhook($this, 'order.updated', ($this->bump)(952, ['customer_note' => 'Gọi trước khi giao']))->assertOk();
    expect(statusOf('952'))->toBe('HANDED_OVER')->and(SyncError::withoutGlobalScopes()->count())->toBe(0)
        ->and(Order::withoutGlobalScopes()->where('external_order_id', '952')->value('buyer_note'))->toBe('Gọi trước khi giao');

    // The website moves on past ECSO: the store wins.
    wooWebhook($this, 'order.updated', ($this->bump)(952, ['status' => 'completed']))->assertOk();
    expect(statusOf('952'))->toBe('COMPLETED');
});

it('writes order actions to the website for a two-way store (the default)', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(953, 'processing', [[11, 1, 'G304-BK', 499000]]))->assertOk();
    $order = Order::withoutGlobalScopes()->where('external_order_id', '953')->sole();

    $this->api->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertOk();
    expect(statusOf('953'))->toBe('PACKED')
        ->and(collect($this->store->orders[953]['meta_data'])->firstWhere('key', '_ecso_stage')['value'])->toBe('packed');
});
