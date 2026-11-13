<?php

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PromotionUsage;

/** Promotions of the shop's own channels (C4): order, product, gift and code promotions on the order form and POS. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->shirt = makeVariant($this->tenant, 'ATC-TRANG-M', ['ref_price' => 159000]);
    $this->pants = makeVariant($this->tenant, 'QK-REU-32', ['ref_price' => 384000]);
    $this->sock = makeVariant($this->tenant, 'VO-TRANG', ['ref_price' => 25000]);
    foreach ([$this->shirt, $this->pants, $this->sock] as $v) {
        stockIn($this->tenant, $v, 50);
    }
    $this->promo = fn (array $data) => $this->api->postJson('/api/v1/promotions', ['name' => 'KM', 'starts_at' => now()->subDay()->toIso8601String(), ...$data])->assertCreated()->json('data');
    $this->order = fn (array $lines, array $extra = []) => createSalesOrder($this->owner, $this->tenant, $lines, $extra);
    $this->fetch = fn (string $id) => Order::withoutGlobalScopes()->where('ulid', $id)->sole();
});

it('applies the best automatic discount, adds gifts at 0 ₫ and records the use', function () {
    ($this->promo)(['name' => 'Giảm 10% đơn từ 500K', 'type' => 'order', 'discount_kind' => 'percent', 'discount_value' => 10, 'max_discount' => 100000, 'min_subtotal' => 500000]);
    ($this->promo)(['name' => 'Giảm 20K', 'type' => 'order', 'discount_kind' => 'amount', 'discount_value' => 20000]);
    $gift = ($this->promo)(['name' => 'Mua 2 áo tặng vớ', 'type' => 'gift', 'buy_qty' => 2, 'gift_variant_id' => $this->sock->ulid, 'variant_ids' => [$this->shirt->ulid]]);

    $id = ($this->order)([[$this->shirt, 2, 159000], [$this->pants, 1, 384000]], ['discount' => 5000])->assertCreated()->json('data.id');
    $order = ($this->fetch)($id);
    // 702.000 goods: 10% = 70.200 beats 20.000; the 5.000 typed by the seller stays on top.
    expect($order->promotion_discount)->toBe('70200.00')->and($order->discount)->toBe('75200.00')->and($order->total)->toBe('626800.00');
    $giftLine = OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->whereNotNull('promotion_id')->sole();
    expect($giftLine->product_variant_id)->toBe($this->sock->id)->and($giftLine->quantity)->toBe(1)->and($giftLine->unit_price)->toBe('0.00');
    expect(PromotionUsage::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(2);

    $detail = $this->api->getJson("/api/v1/orders/{$id}")->assertOk()->json('data');
    expect(collect($detail['items'])->where('is_gift', true))->toHaveCount(1)->and(collect($detail['promotions'])->pluck('name')->all())->toContain('Mua 2 áo tặng vớ');
    $list = collect($this->api->getJson('/api/v1/promotions')->json('data'))->keyBy('id');
    expect($list[$gift['id']]['uses'])->toBe(1)->and($list[$gift['id']]['state'])->toBe('running');

    // Editing the order keeps the seller's discount apart and recomputes the promotion.
    $this->api->putJson("/api/v1/sales-orders/{$id}", ['lines' => [['variant_id' => $this->shirt->ulid, 'quantity' => 1, 'unit_price' => 159000]]])->assertOk();
    $order->refresh();
    expect($order->promotion_discount)->toBe('20000.00')->and($order->discount)->toBe('25000.00')
        ->and(OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->whereNotNull('promotion_id')->count())->toBe(0);
});

it('takes a code once per customer, refuses wrong or used codes and respects channels, limits and dates', function () {
    ($this->promo)(['name' => 'Khách mới giảm 50K', 'type' => 'order', 'code' => 'chaoban50', 'discount_kind' => 'amount', 'discount_value' => 50000, 'per_customer_limit' => 1]);
    $customer = ['customer' => ['name' => 'Trần Thị Mai', 'phone' => '0938123321']];

    ($this->order)([[$this->shirt, 1, 159000]], ['coupon_code' => 'SAIMA'])->assertStatus(422)->assertJsonPath('errors.coupon_code.0', 'Mã SAIMA không đúng hoặc không dùng được cho đơn này.');
    ($this->order)([[$this->shirt, 1, 159000]], ['coupon_code' => 'CHAOBAN50'])->assertStatus(422)->assertJsonPath('errors.coupon_code.0', fn ($m) => str_contains($m, 'Chọn khách hàng'));
    // The order form previews a buyer typed in (saved with the order) as a customer who never used the code.
    $line = [['variant_id' => $this->shirt->ulid, 'quantity' => 1, 'unit_price' => 159000]];
    expect($this->api->postJson('/api/v1/promotions/preview', ['coupon_code' => 'CHAOBAN50', 'lines' => $line])->json('data.code_error'))->toContain('Chọn khách hàng');
    $this->api->postJson('/api/v1/promotions/preview', ['coupon_code' => 'CHAOBAN50', 'new_customer' => true, 'lines' => $line])
        ->assertOk()->assertJsonPath('data.discount', '50000.00')->assertJsonPath('data.code_error', null);
    $id = ($this->order)([[$this->shirt, 1, 159000]], ['coupon_code' => 'chaoban50', ...$customer])->assertCreated()->json('data.id');
    expect(($this->fetch)($id)->promotion_discount)->toBe('50000.00')->and(($this->fetch)($id)->coupon_code)->toBe('CHAOBAN50');
    $customerId = Customer::withoutGlobalScopes()->find(($this->fetch)($id)->customer_id)?->ulid;
    ($this->order)([[$this->shirt, 1, 159000]], ['coupon_code' => 'CHAOBAN50', 'customer_id' => $customerId])->assertStatus(422)->assertJsonPath('errors.coupon_code.0', 'Khách này đã dùng mã CHAOBAN50.');

    // A cancelled order gives its use back.
    $this->api->postJson("/api/v1/orders/{$id}/actions/cancel", ['reason' => 'Khách đổi ý'])->assertOk();
    ($this->order)([[$this->shirt, 1, 159000]], ['coupon_code' => 'CHAOBAN50', 'customer_id' => $customerId])->assertCreated();

    // Another channel, a promotion not started yet and a paused one never apply.
    $other = $this->api->postJson('/api/v1/sales-channels', ['name' => 'Website', 'type' => 'website'])->assertCreated()->json('data.id');
    ($this->promo)(['name' => 'Chỉ website', 'type' => 'order', 'discount_kind' => 'amount', 'discount_value' => 30000, 'channel_ids' => [$other]]);
    ($this->promo)(['name' => 'Sắp diễn ra', 'type' => 'order', 'discount_kind' => 'amount', 'discount_value' => 40000, 'starts_at' => now()->addDay()->toIso8601String()]);
    $paused = ($this->promo)(['name' => 'Tạm dừng', 'type' => 'order', 'discount_kind' => 'amount', 'discount_value' => 60000]);
    $this->api->putJson("/api/v1/promotions/{$paused['id']}", ['status' => 'paused'])->assertOk()->assertJsonPath('data.state', 'paused');
    $plain = ($this->order)([[$this->shirt, 1, 159000]])->assertCreated()->json('data.id');
    expect(($this->fetch)($plain)->promotion_discount)->toBe('0.00');
});

it('prices product promotions per line, previews the same result and lets only price editors turn promotions off', function () {
    ($this->promo)(['name' => 'Áo đồng giá 99K', 'type' => 'product', 'discount_kind' => 'fixed_price', 'discount_value' => 99000, 'variant_ids' => [$this->shirt->ulid]]);
    $preview = $this->api->postJson('/api/v1/promotions/preview', ['channel_id' => defaultChannel($this->tenant)->ulid, 'lines' => [
        ['variant_id' => $this->shirt->ulid, 'quantity' => 3, 'unit_price' => 159000], ['variant_id' => $this->pants->ulid, 'quantity' => 1, 'unit_price' => 384000],
    ]])->assertOk()->json('data');
    expect($preview['discount'])->toBe('180000.00')->and($preview['applied'][0]['name'])->toBe('Áo đồng giá 99K');

    $id = ($this->order)([[$this->shirt, 3, 159000], [$this->pants, 1, 384000]])->assertCreated()->json('data.id');
    expect(($this->fetch)($id)->promotion_discount)->toBe('180000.00');
    $off = ($this->order)([[$this->shirt, 3, 159000]], ['promotions_off' => true])->assertCreated()->json('data.id');
    expect(($this->fetch)($off)->promotion_discount)->toBe('0.00');

    $staff = addMember($this->tenant, 'ORDER_STAFF');
    $this->api->putJson('/api/v1/tenant/settings/pricing', ['staff_edit_price' => false])->assertOk();
    createSalesOrder($staff, $this->tenant, [[$this->shirt, 1, 159000]], ['promotions_off' => true])->assertStatus(422)->assertJsonPath('errors.promotions_off.0', 'Bạn không có quyền bỏ khuyến mãi trên đơn.');
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/promotions', ['name' => 'X', 'type' => 'order', 'discount_kind' => 'amount', 'discount_value' => 1])->assertForbidden();
});

it('validates promotions and keeps used ones from being deleted', function () {
    $this->api->postJson('/api/v1/promotions', ['name' => 'Sai', 'type' => 'order', 'discount_kind' => 'percent', 'discount_value' => 150])->assertStatus(422)->assertJsonValidationErrors('discount_value');
    $this->api->postJson('/api/v1/promotions', ['name' => 'Thiếu quà', 'type' => 'gift', 'buy_qty' => 2])->assertStatus(422)->assertJsonValidationErrors('gift_variant_id');
    $p = ($this->promo)(['name' => 'Mã A', 'type' => 'order', 'code' => 'MAA', 'discount_kind' => 'amount', 'discount_value' => 10000]);
    $this->api->postJson('/api/v1/promotions', ['name' => 'Trùng mã', 'type' => 'order', 'code' => 'maa', 'discount_kind' => 'amount', 'discount_value' => 1])->assertStatus(422)->assertJsonValidationErrors('code');
    ($this->order)([[$this->shirt, 1, 159000]], ['coupon_code' => 'MAA'])->assertCreated();
    $this->api->deleteJson("/api/v1/promotions/{$p['id']}")->assertStatus(422);
    $unused = ($this->promo)(['name' => 'Chưa dùng', 'type' => 'order', 'discount_kind' => 'amount', 'discount_value' => 1, 'starts_at' => now()->addMonth()->toIso8601String()]);
    $this->api->deleteJson("/api/v1/promotions/{$unused['id']}")->assertNoContent();
});
