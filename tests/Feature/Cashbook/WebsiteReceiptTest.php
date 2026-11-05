<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(now()->setTime(5, 0)));

/** A website (WooCommerce) order the store reports as paid. */
function websiteOrder($user, $tenant, int $price, array $extra = []): string
{
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $id = createSalesOrder($user, $tenant, [[$variant, 1, $price]], $extra)->assertCreated()->json('data.id');
    DB::table('orders')->where('ulid', $id)->update(['marketplace' => 'woocommerce', 'payment_status' => Order::PAYMENT_PAID, 'paid_amount' => $price]);

    return $id;
}

it('books website order money into the bank fund only once someone confirms it, without touching the order status', function () {
    [$tenant, $owner] = tenantWithOwner();
    $id = websiteOrder($owner, $tenant, 400000);
    $bank = fund($owner, $tenant, 'Tài khoản ngân hàng');

    // Reported paid by the store, but nothing in a fund yet.
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.unconfirmed_amount', '400000.00')
        ->assertJsonPath('data.payment_status', 'paid');

    postOnce($owner, $tenant, "/api/v1/orders/{$id}/payments", ['method' => 'bank_transfer', 'amount' => 300000, 'reference' => 'GD1'], 'w-1')->assertCreated();
    expect(fund($owner, $tenant, 'Tài khoản ngân hàng')['balance'])->toEqual($bank['balance'] + 300000);
    $order = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.unconfirmed_amount', '100000.00')
        ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.paid_amount', '400000.00')->json('data');

    // No more than the store reported; refunds are not booked from here.
    postOnce($owner, $tenant, "/api/v1/orders/{$id}/payments", ['method' => 'bank_transfer', 'amount' => 200000], 'w-2')->assertUnprocessable()->assertJsonValidationErrors('amount');
    postOnce($owner, $tenant, "/api/v1/orders/{$id}/payments", ['kind' => 'refund', 'method' => 'bank_transfer', 'amount' => 1000], 'w-3')->assertUnprocessable();

    // Picking another fund; voiding a confirmation gives the money back to "unconfirmed" and keeps the store status.
    $cash = fund($owner, $tenant, 'Tiền mặt');
    postOnce($owner, $tenant, "/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 100000, 'account' => $cash['id']], 'w-4')->assertCreated();
    expect(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual($cash['balance'] + 100000);
    actingInTenant($owner, $tenant)->postJson("/api/v1/payments/{$order['payments'][0]['id']}/void", ['reason' => 'Nhầm'])->assertOk();
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.unconfirmed_amount', '300000.00')
        ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.paid_amount', '400000.00');

    // The cash book shows the receipt; the customer owes nothing for a website order.
    $rows = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?direction=receipt')->json('data');
    expect(collect($rows)->where('document.id', $id)->where('status', 'completed')->sum('amount'))->toEqual(100000);

    // A member without the payment permission cannot confirm.
    actingInTenant(addMember($tenant, Role::VIEWER), $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'bank_transfer', 'amount' => 1000])->assertForbidden();
});

it('matches website COD orders in a carrier statement', function () {
    [$tenant, $owner] = tenantWithOwner();
    $id = websiteOrder($owner, $tenant, 250000, ['is_cod' => true, 'carrier' => 'GHN', 'tracking_number' => 'GHNW1']);
    DB::table('orders')->where('ulid', $id)->update(['payment_status' => Order::PAYMENT_UNPAID, 'paid_amount' => 0]);

    $preview = actingInTenant($owner, $tenant)->postJson('/api/v1/cod-reconciliations/preview', ['lines' => [['tracking' => 'GHNW1', 'cod' => 250000, 'fee' => 20000]]])->assertOk()->json('data');
    expect($preview['lines'][0]['status'])->toBe('matched');
    postOnce($owner, $tenant, '/api/v1/cod-reconciliations', ['carrier' => 'ghn', 'lines' => [['tracking' => 'GHNW1', 'cod' => 250000, 'fee' => 20000]]], 'wc-1')->assertCreated();
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.unconfirmed_amount', '0.00');
});
