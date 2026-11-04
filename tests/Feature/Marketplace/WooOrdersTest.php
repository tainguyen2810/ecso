<?php

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncCursor;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\Customer;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeWooStore;

/** WooCommerce orders, round A: the whole order, the customer card, the "orders since" cut-off and the sync log. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->mouse = makeVariant($this->tenant, 'G304-BK', ['product_name' => 'Chuột G304', 'ref_price' => 499000]);
    stockIn($this->tenant, $this->mouse, 12);
    $this->store = new FakeWooStore;
    $this->store->addSimple(11, 'G304-BK', 'Chuột Logitech G304', '549000', '499000', stock: 40);
    config(['app.url' => 'https://erp.example.vn']);
    $this->account = wooConnect($this);
    wooMap($this, $this->account);
});

function wooOrder(string $id): Order
{
    return Order::withoutGlobalScopes()->where('external_order_id', $id)->sole();
}

it('keeps the whole website order: contact, addresses, coupon, tax, fees, transaction and paid status', function () {
    $o = $this->store->addOrder(801, 'processing', [[11, 2, 'G304-BK', 499000]], 'bacs');
    $o['number'] = 'WEB-801';
    $o['customer_id'] = 37;
    $o['shipping'] = ['first_name' => 'Lan', 'last_name' => 'Nguyễn', 'address_1' => '5 Trần Phú', 'address_2' => 'Phường Vĩnh Ninh', 'city' => 'Huế', 'phone' => '0901234567'];
    $o['coupon_lines'] = [['code' => 'GIAM50K', 'discount' => '50000']];
    $o['discount_total'] = '50000';
    $o['total_tax'] = '79840';
    $o['line_items'][0]['total'] = '948000';
    $o['line_items'][0]['total_tax'] = '79840';
    $o['fee_lines'] = [['name' => 'Phí gói quà', 'total' => '15000']];
    $o['transaction_id'] = 'VCB-123456';
    $o['date_paid_gmt'] = now()->utc()->format('Y-m-d\TH:i:s');
    $this->store->orders[801] = $o;

    wooWebhook($this, 'order.created', $o)->assertOk();

    $order = wooOrder('801');
    expect($order->external_number)->toBe('WEB-801')
        ->and($order->buyer_phone)->toBe('0901234567')
        ->and($order->buyer_email)->toBe('lan@example.vn')
        ->and($order->getRawOriginal('buyer_email'))->not->toContain('lan@')
        ->and($order->addresses['shipping']['address_2'])->toBe('Phường Vĩnh Ninh')
        ->and($order->addresses['billing']['email'])->toBe('lan@example.vn')
        ->and($order->external_customer_id)->toBe('37')
        ->and($order->coupon_codes)->toBe(['GIAM50K'])
        ->and((string) $order->tax_total)->toBe('79840.00')
        ->and((string) $order->fee_total)->toBe('15000.00')
        ->and($order->transaction_id)->toBe('VCB-123456')
        ->and($order->payment_status)->toBe(Order::PAYMENT_PAID)
        ->and((string) $order->paid_amount)->toBe((string) $order->total)
        // Billing/shipping live only in the encrypted column, not in the plain raw copy.
        ->and($order->raw)->not->toHaveKey('billing')->and($order->raw)->toHaveKey('coupon_lines');

    $item = TenantContext::run($this->tenant, fn () => $order->items()->sole());
    expect((string) $item->tax)->toBe('79840.00')->and((string) $item->discount)->toBe('50000.00');

    // The order page shows the full contact for the seller's own website.
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/orders/{$order->ulid}")->assertOk()
        ->assertJsonPath('data.buyer_phone', '0901234567')
        ->assertJsonPath('data.buyer_email', 'lan@example.vn')
        ->assertJsonPath('data.coupon_codes', ['GIAM50K'])
        ->assertJsonPath('data.external_number', 'WEB-801');
});

it('does not take a completed COD order as paid', function () {
    $o = $this->store->addOrder(802, 'completed', [[11, 1, 'G304-BK', 499000]], 'cod');
    $o['date_paid_gmt'] = now()->utc()->format('Y-m-d\TH:i:s'); // Woo sets it on completion
    $this->store->orders[802] = $o;

    wooWebhook($this, 'order.created', $o)->assertOk();

    expect(wooOrder('802')->payment_status)->toBe(Order::PAYMENT_UNPAID)->and((string) wooOrder('802')->paid_amount)->toBe('0.00');
});

it('puts the orders of one buyer on one customer card, found by phone', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(803, 'processing', [[11, 1, 'G304-BK', 499000]]))->assertOk();
    wooWebhook($this, 'order.created', $this->store->addOrder(804, 'processing', [[11, 1, 'G304-BK', 499000]]))->assertOk();

    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();
    expect($customers)->toHaveCount(1)
        ->and($customers[0]->name)->toBe('Lan Nguyễn')
        ->and($customers[0]->email)->toBe('lan@example.vn')
        ->and(wooOrder('803')->customer_id)->toBe($customers[0]->id)
        ->and(wooOrder('804')->customer_id)->toBe($customers[0]->id);

    // Website orders count as purchases of the customer, never as debt owed to the shop (paid on the website).
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/customers/{$customers[0]->ulid}")->assertOk()
        ->assertJsonPath('data.orders_count', 2)
        ->assertJsonPath('data.debt', fn ($v) => (float) $v === 0.0);

    // A phone that is not a number does not stop the order; it just gets no customer card.
    $o = $this->store->addOrder(805, 'processing', [[11, 1, 'G304-BK', 499000]]);
    $o['billing']['phone'] = 'gọi zalo';
    $this->store->orders[805] = $o;
    wooWebhook($this, 'order.created', $o)->assertOk();
    expect(wooOrder('805')->customer_id)->toBeNull();
});

it('skips orders created before order sync started, by webhook and by reconcile', function () {
    $old = $this->store->addOrder(810, 'processing', [[11, 1, 'G304-BK', 499000]]);
    $old['date_created_gmt'] = now()->subHour()->utc()->format('Y-m-d\TH:i:s');
    $this->store->orders[810] = $old;

    // An update of an old order arrives by webhook: not taken in, no stock held.
    wooWebhook($this, 'order.updated', $old)->assertOk();
    expect(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->mouse)->reserved)->toBe(0);

    // The reconcile asks Woo only for orders created after the cut-off.
    $this->store->addOrder(811, 'processing', [[11, 1, 'G304-BK', 499000]]);
    Artisan::call('ecso:orders:reconcile');
    expect(Order::withoutGlobalScopes()->pluck('external_order_id')->all())->toBe(['811']);

    // Turning order sync off then on starts again from that moment.
    $api = actingInTenant($this->owner, $this->tenant);
    $api->patchJson("/api/v1/marketplace-accounts/{$this->account->ulid}", ['settings' => ['sync_orders' => false]])->assertOk();
    $this->travel(2)->hours();
    $api->patchJson("/api/v1/marketplace-accounts/{$this->account->ulid}", ['settings' => ['sync_orders' => true]])->assertOk()
        ->assertJsonPath('data.orders_since', fn ($v) => $v !== null);
    expect(MarketplaceAccount::withoutGlobalScopes()->find($this->account->id)->orders_since->greaterThan(now()->subMinute()))->toBeTrue();
});

it('logs every reconcile pass; a busy order does not stop the others and keeps the cursor', function () {
    $this->store->addOrder(820, 'processing', [[11, 1, 'G304-BK', 499000]]);
    $this->store->addOrder(821, 'processing', [[11, 1, 'G304-BK', 499000]]);
    $this->store->failOrders = [820];

    Artisan::call('ecso:orders:reconcile');

    $run = SyncRun::withoutGlobalScopes()->latest('id')->first();
    expect($run->status)->toBe(SyncRun::PARTIAL)
        ->and($run->counts)->toBe(['failed' => 1, 'created' => 1])
        ->and($run->error)->toContain('Đơn 820')
        ->and(Order::withoutGlobalScopes()->pluck('external_order_id')->all())->toBe(['821'])
        ->and(SyncCursor::withoutGlobalScopes()->where('marketplace_account_id', $this->account->id)->value('last_synced_at'))->toBeNull();

    // Next pass (15 minutes later, the default interval): the store answers again, the missed order comes in.
    $this->store->failOrders = [];
    $this->travel(15)->minutes();
    Artisan::call('ecso:orders:reconcile');
    expect(SyncRun::withoutGlobalScopes()->latest('id')->first()->status)->toBe(SyncRun::OK)
        ->and(Order::withoutGlobalScopes()->count())->toBe(2)
        ->and(SyncCursor::withoutGlobalScopes()->where('marketplace_account_id', $this->account->id)->value('last_synced_at'))->not->toBeNull();

    // The store cannot even list orders: the pass is logged as failed.
    $this->store->failList = true;
    $this->travel(15)->minutes();
    Artisan::call('ecso:orders:reconcile');
    $failed = SyncRun::withoutGlobalScopes()->latest('id')->first();
    expect($failed->status)->toBe(SyncRun::FAILED)->and($failed->error)->not->toBeEmpty();
});

it('keeps marketplace buyers masked: only the own website gets the full contact', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'X-1');
    $order = fakeOrder(fakeShop($tenant), [[$variant, 1]]);

    $json = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order->ulid}")->assertOk()->json('data');
    // The marketplace buyer is known by their account (a card with a name only), never by their contact details.
    $card = TenantContext::run($tenant, fn () => Customer::query()->find($order->fresh()->customer_id));
    expect($json)->not->toHaveKey('buyer_email')->and($json)->not->toHaveKey('addresses')
        ->and($card->phone)->toBeNull()->and($card->email)->toBeNull()->and($card->address)->toBeNull();
});

it('gives the stock back when a website order is refunded before it ships, and opens a return after', function () {
    $refund = function (array $o) {
        $this->travel(1)->minutes();
        $o['status'] = 'refunded';
        $o['date_modified_gmt'] = now()->utc()->format('Y-m-d\TH:i:s');
        $this->store->orders[$o['id']] = $o;
        wooWebhook($this, 'order.updated', $o)->assertOk();
    };

    // Refunded before packing: a cancel; the 2 held units are free again, nothing leaves stock.
    $o = $this->store->addOrder(830, 'processing', [[11, 2, 'G304-BK', 499000]]);
    wooWebhook($this, 'order.created', $o)->assertOk();
    expect(balanceOf(defaultWarehouse($this->tenant), $this->mouse)->reserved)->toBe(2);
    $refund($o);
    $b = balanceOf(defaultWarehouse($this->tenant), $this->mouse);
    expect(wooOrder('830')->status)->toBe('CANCELLED')->and($b->reserved)->toBe(0)->and($b->on_hand)->toBe(12)
        ->and(OrderReturn::withoutGlobalScopes()->where('order_id', wooOrder('830')->id)->count())->toBe(0);

    // Completed (shipped) then refunded: the goods left; a return waits for them, stock comes back only on inspection.
    $o = $this->store->addOrder(831, 'completed', [[11, 1, 'G304-BK', 499000]]);
    wooWebhook($this, 'order.created', $o)->assertOk();
    expect(balanceOf(defaultWarehouse($this->tenant), $this->mouse)->on_hand)->toBe(11);
    $refund($o);
    $return = OrderReturn::withoutGlobalScopes()->where('order_id', wooOrder('831')->id)->sole();
    expect(wooOrder('831')->status)->toBe('RETURNED')->and($return->status)->toBe(OrderReturn::IN_TRANSIT)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->mouse)->on_hand)->toBe(11);
});
