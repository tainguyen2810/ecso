<?php

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderRefund;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\Support\TenantContext;
use Tests\Support\FakeWooStore;

/** WooCommerce orders, round D: refunds made on the website, partial, repeated, by line. */
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

function refundStock($test): array
{
    $b = balanceOf(defaultWarehouse($test->tenant), $test->mouse);

    return [$b->on_hand, $b->reserved];
}

function refundedOrder(string $id): Order
{
    return Order::withoutGlobalScopes()->where('external_order_id', $id)->sole();
}

it('holds less stock when part of an order is refunded before it ships', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(960, 'processing', [[11, 3, 'G304-BK', 499000]]))->assertOk();
    expect(refundStock($this))->toBe([12, 3]);

    $this->travel(1)->minutes();
    $line = $this->store->orders[960]['line_items'][0]['id'];
    wooWebhook($this, 'order.updated', $this->store->refund(960, 499000, [[$line, 1]], 'Khách đổi ý 1 cái'))->assertOk();

    $order = refundedOrder('960');
    expect(refundStock($this))->toBe([12, 2])
        ->and((string) $order->refunded_amount)->toBe('499000.00')
        ->and(OrderRefund::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(1)
        ->and(OrderReturn::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(0);

    // Shipping now takes 2 out of stock, not 3.
    $this->travel(1)->minutes();
    $this->store->orders[960]['status'] = 'completed';
    $this->store->orders[960]['date_modified_gmt'] = now()->utc()->format('Y-m-d\TH:i:s');
    wooWebhook($this, 'order.updated', $this->store->orders[960])->assertOk();
    expect(refundStock($this))->toBe([10, 0]);
});

it('opens one return per refund of shipped goods, never twice, and never adds stock by itself', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(961, 'completed', [[11, 3, 'G304-BK', 499000]]))->assertOk();
    expect(refundStock($this))->toBe([9, 0]);
    $line = $this->store->orders[961]['line_items'][0]['id'];

    $this->travel(1)->minutes();
    $payload = $this->store->refund(961, 499000, [[$line, 1]], 'Lỗi chuột');
    wooWebhook($this, 'order.updated', $payload)->assertOk();
    wooWebhook($this, 'order.updated', $payload)->assertOk(); // the same delivery again

    $order = refundedOrder('961');
    $returns = TenantContext::run($this->tenant, fn () => OrderReturn::query()->with('lines')->where('order_id', $order->id)->get());
    expect($returns)->toHaveCount(1)
        ->and($returns[0]->status)->toBe(OrderReturn::REQUESTED)
        ->and($returns[0]->lines->sum('quantity'))->toBe(1)
        ->and(refundStock($this))->toBe([9, 0]);

    // A second refund of another unit, then a refund of money only (shipping fee).
    $this->travel(1)->minutes();
    wooWebhook($this, 'order.updated', $this->store->refund(961, 499000, [[$line, 1]]))->assertOk();
    $this->travel(1)->minutes();
    wooWebhook($this, 'order.updated', $this->store->refund(961, 30000))->assertOk();

    expect(OrderRefund::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(3)
        ->and(OrderReturn::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(2)
        ->and((string) $order->fresh()->refunded_amount)->toBe('1028000.00')
        ->and(refundStock($this))->toBe([9, 0]);

    $json = actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/orders/{$order->ulid}")->assertOk()->json('data');
    expect($json['refunds'])->toHaveCount(3)
        ->and($json['refunds'][0]['reason'])->toBe('Lỗi chuột')
        ->and($json['refunds'][0]['return']['status'])->toBe(OrderReturn::REQUESTED)
        ->and($json['refunds'][2]['lines'])->toBe([])
        ->and($json['items'][0]['refunded_quantity'])->toBe(2);
});
