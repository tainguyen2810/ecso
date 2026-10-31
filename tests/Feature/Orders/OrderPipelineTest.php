<?php

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderIngestor;
use App\Modules\Orders\Services\ReturnService;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    $this->pants = makeVariant($this->tenant, 'QUAN-001', ['product_name' => 'Quần jean', 'ref_price' => 350000]);
    stockIn($this->tenant, $this->shirt, 10);
    stockIn($this->tenant, $this->pants, 5);
    $this->shop = fakeShop($this->tenant);
    $this->fake = app(FakeMarketplace::class);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

function stockOf($test, $variant): array
{
    $b = balanceOf($test->warehouse, $variant);

    return [$b->on_hand, $b->reserved];
}

it('imports an order from a signed webhook and reserves its stock', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2], [$this->pants, 1]]);

    expect($order->status)->toBe(OrderStatus::READY_TO_PACK)
        ->and($order->raw_status)->toBe('fake_ready')
        ->and($order->has_unmapped)->toBeFalse()
        ->and($order->has_shortage)->toBeFalse()
        ->and(stockOf($this, $this->shirt))->toBe([10, 2])
        ->and(stockOf($this, $this->pants))->toBe([5, 1]);

    $event = WebhookEvent::query()->latest('id')->first();
    expect($event->status)->toBe(WebhookEvent::PROCESSED)
        ->and($event->tenant_id)->toBe($this->tenant->id)
        ->and($event->result)->toBe('created');

    $history = OrderStatusHistory::withoutGlobalScopes()->where('order_id', $order->id)->get();
    expect($history)->toHaveCount(1)->and($history[0]->to_status)->toBe(OrderStatus::READY_TO_PACK)->and($history[0]->source)->toBe('webhook');
});

it('stores a webhook delivered twice once and holds stock once', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 3]]);
    $first = WebhookEvent::query()->latest('id')->first();

    // Same event id again (marketplace retry): deduplicated at the inbox.
    $again = $this->fake->notify($order->external_order_id, $first->external_event_id);
    expect($again['duplicate'])->toBeTrue();

    // A different event id for the same snapshot (webhook + poll): order unchanged, no second reserve.
    $this->fake->notify($order->external_order_id);
    expect(WebhookEvent::query()->latest('id')->first()->result)->toBe('unchanged');

    expect(WebhookEvent::query()->where('external_order_id', $order->external_order_id)->count())->toBe(2)
        ->and(stockOf($this, $this->shirt))->toBe([10, 3])
        ->and(StockMovement::withoutGlobalScopes()->where('type', 'RESERVE')->count())->toBe(1);
});

it('ignores an older snapshot that arrives after a newer one', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $old = CarbonImmutable::parse($order->external_updated_at);

    $this->fake->setStatus($order->external_order_id, 'fake_packed');
    expect($order->refresh()->status)->toBe(OrderStatus::PACKED);

    $stale = new OrderData($order->external_order_id, 'fake_ready', $old, []);
    $result = TenantContext::run($this->tenant, fn () => app(OrderIngestor::class)->ingest($this->shop, $stale, 'poll'));

    expect($result['result'])->toBe(OrderIngestor::STALE)->and($order->refresh()->status)->toBe(OrderStatus::PACKED);
});

it('refuses a backwards transition even with a newer timestamp', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $this->fake->setStatus($order->external_order_id, 'fake_delivered');

    $back = new OrderData($order->external_order_id, 'fake_ready', CarbonImmutable::now()->addMinute(), []);
    $result = TenantContext::run($this->tenant, fn () => app(OrderIngestor::class)->ingest($this->shop, $back, 'poll'));

    expect($result['result'])->toBe(OrderIngestor::IGNORED_TRANSITION)
        ->and($order->refresh()->status)->toBe(OrderStatus::DELIVERED)
        ->and($order->sync_warning)->toContain('Bỏ qua');
});

it('applies skipped steps: ready to delivered ships the stock', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $this->fake->setStatus($order->external_order_id, 'fake_delivered');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::DELIVERED)
        ->and($order->handed_over_at)->not->toBeNull()
        ->and(stockOf($this, $this->shirt))->toBe([8, 0])
        ->and(OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->first()->cost_snapshot)->not->toBeNull();
});

it('releases reservations when cancelled before shipping, only once', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 4]]);
    $this->fake->setStatus($order->external_order_id, 'fake_cancelled');
    expect(stockOf($this, $this->shirt))->toBe([10, 0]);

    $this->fake->notify($order->external_order_id); // repeated cancel event
    expect(stockOf($this, $this->shirt))->toBe([10, 0])
        ->and(StockMovement::withoutGlobalScopes()->where('type', 'RELEASE')->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::CANCELLED);
});

it('does not add stock back for a cancel after handover until the parcel is inspected', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $this->fake->setStatus($order->external_order_id, 'fake_shipped');
    expect(stockOf($this, $this->shirt))->toBe([8, 0]);

    $this->fake->setStatus($order->external_order_id, 'fake_cancelled');
    expect(stockOf($this, $this->shirt))->toBe([8, 0]);

    $return = OrderReturn::withoutGlobalScopes()->where('order_id', $order->id)->sole();
    expect($return->type)->toBe(OrderReturn::TYPE_CANCEL_AFTER_SHIP)->and($return->status)->toBe(OrderReturn::IN_TRANSIT);

    inTenant($this->tenant);
    $service = app(ReturnService::class);
    $service->move($return, OrderReturn::RECEIVED);
    $line = $return->lines()->first();
    $service->inspect($return, $this->warehouse, [['line_id' => $line->id, 'received_qty' => 2, 'sellable_qty' => 1, 'damaged_qty' => 1]]);

    expect(stockOf($this, $this->shirt))->toBe([9, 0])
        ->and($return->refresh()->status)->toBe(OrderReturn::INSPECTED);

    // Inspecting again is refused and cannot restock twice.
    expect(fn () => $service->inspect($return, $this->warehouse, [['line_id' => $line->id, 'received_qty' => 2, 'sellable_qty' => 2, 'damaged_qty' => 0]]))
        ->toThrow(ValidationException::class);
    expect(stockOf($this, $this->shirt))->toBe([9, 0]);
});

it('never adds stock for a refund without returned goods', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $this->fake->setStatus($order->external_order_id, 'fake_delivered');
    inTenant($this->tenant);

    $refund = app(ReturnService::class)->create($order, ['type' => OrderReturn::TYPE_REFUND_ONLY, 'refund_amount' => 50000]);
    app(ReturnService::class)->move($refund, OrderReturn::APPROVED);
    expect(fn () => app(ReturnService::class)->move($refund, OrderReturn::RECEIVED))->toThrow(ValidationException::class);
    app(ReturnService::class)->move($refund, OrderReturn::CLOSED);

    expect(stockOf($this, $this->shirt))->toBe([9, 0])
        ->and(StockMovement::withoutGlobalScopes()->where('type', 'RETURN_RESTOCK')->count())->toBe(0);
});

it('holds what is available on shortage and fills the rest when stock arrives', function () {
    $order = fakeOrder($this->shop, [[$this->pants, 8]]);

    expect(stockOf($this, $this->pants))->toBe([5, 5])->and($order->refresh()->has_shortage)->toBeTrue();

    // Older orders first: a second short order waits behind the first.
    $second = fakeOrder($this->shop, [[$this->pants, 2]]);
    expect($second->refresh()->has_shortage)->toBeTrue();

    stockIn($this->tenant, $this->pants, 4);
    expect(stockOf($this, $this->pants))->toBe([9, 9])
        ->and($order->refresh()->has_shortage)->toBeFalse()
        ->and($second->refresh()->has_shortage)->toBeTrue();
});

it('keeps unmapped items out of stock and reserves them once the listing is mapped', function () {
    $loose = makeVariant($this->tenant, 'NON-001', ['product_name' => 'Nón']);
    stockIn($this->tenant, $loose, 3);
    // A listing whose seller SKU does not match exactly (must not be auto-mapped).
    $this->fake->putProducts($this->shop->external_shop_id, [[
        'product_id' => 'FPN', 'sku_id' => 'FSN', 'seller_sku' => 'NON-001-X', 'title' => 'Nón', 'variant_name' => null, 'price' => 90000,
    ]]);
    $remote = $this->fake->createOrder($this->shop->external_shop_id, [['sku_id' => 'FSN', 'quantity' => 1]]);
    $order = Order::withoutGlobalScopes()->where('external_order_id', $remote['order_sn'])->sole();

    expect($order->has_unmapped)->toBeTrue()->and(stockOf($this, $loose))->toBe([3, 0]);

    TenantContext::run($this->tenant, function () use ($loose) {
        $listing = ListingVariant::query()->where('external_sku_id', 'FSN')->sole();
        expect($listing->mapping_status)->toBe('unmapped');
        app(ListingService::class)->map($listing, $loose);
    });

    expect($order->refresh()->has_unmapped)->toBeFalse()->and(stockOf($this, $loose))->toBe([3, 1]);
});

it('maps an unknown raw status to UNKNOWN_STATUS and keeps the reservation', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $odd = new OrderData($order->external_order_id, 'fake_mystery', CarbonImmutable::now()->addMinute(), []);
    TenantContext::run($this->tenant, fn () => app(OrderIngestor::class)->ingest($this->shop, $odd, 'poll'));

    expect($order->refresh()->status)->toBe(OrderStatus::UNKNOWN_STATUS)
        ->and($order->sync_warning)->toContain('fake_mystery')
        ->and(stockOf($this, $this->shirt))->toBe([10, 1]);
});
