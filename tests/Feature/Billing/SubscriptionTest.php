<?php

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Payments\GatewayRegistry;
use App\Modules\Billing\Payments\InvalidPaymentNotification;
use App\Modules\Billing\Payments\PaymentGateway;
use App\Modules\Billing\Payments\PaymentNotification;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

function superAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    return $admin;
}

function subscriptionOf(Tenant $tenant): Subscription
{
    return Subscription::withoutGlobalScopes()->with(['plan', 'pendingPlan'])->where('tenant_id', $tenant->id)->firstOrFail();
}

function invoiceByUlid(string $ulid): Invoice
{
    return Invoice::withoutGlobalScopes()->where('ulid', $ulid)->firstOrFail();
}

/** Puts the tenant on a paid plan for a running period, as if an invoice had been paid. */
function onPaidPlan(Tenant $tenant, string $planKey, string $cycle = 'monthly', ?CarbonImmutable $start = null): Subscription
{
    $start ??= CarbonImmutable::now()->subDays(10);
    $sub = subscriptionOf($tenant);
    $sub->forceFill([
        'plan_id' => Plan::query()->where('key', $planKey)->value('id'),
        'billing_cycle' => $cycle,
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_start' => $start,
        'current_period_end' => $cycle === 'yearly' ? $start->addYear() : $start->addMonth(),
    ])->save();

    return $sub->refresh();
}

function confirmTransfer(Invoice $invoice, string $reference, float $amount): array
{
    Sanctum::actingAs(superAdmin());

    return test()->postJson("/api/v1/admin/invoices/{$invoice->ulid}/payments", [
        'reference' => $reference, 'amount' => $amount, 'received_at' => now()->subMinute()->toIso8601String(),
    ])->json('data');
}

it('shows plans with yearly prices, usage and trial availability', function () {
    [$tenant, $owner] = tenantWithOwner();

    $data = actingInTenant($owner, $tenant)->getJson('/api/v1/subscription')->assertOk()->json('data');

    expect($data['plan']['key'])->toBe('free')
        ->and($data['trial_available'])->toBeTrue()
        ->and($data['usage'])->toHaveKeys(['limit.users', 'limit.shops', 'limit.warehouses', 'limit.orders_per_month'])
        ->and(collect($data['plans'])->firstWhere('key', 'pro')['price_yearly'])->toEqual(4990000)
        ->and($data['open_invoice'])->toBeNull();
});

it('issues an invoice with bank transfer instructions and changes the plan only once it is paid', function () {
    config(['billing.bank' => ['name' => 'VCB', 'account_number' => '0123', 'account_name' => 'CONG TY ECSO']]);
    [$tenant, $owner] = tenantWithOwner();

    $invoice = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'starter', 'cycle' => 'monthly'])
        ->assertCreated()->assertJsonPath('data.action', 'invoice')->json('data.invoice');

    expect($invoice['amount'])->toEqual(199000)
        ->and($invoice['status'])->toBe('pending')
        ->and($invoice['transfer_code'])->toStartWith('ECSO')
        ->and($invoice['payment']['configured'])->toBeTrue()
        ->and($invoice['payment']['account_number'])->toBe('0123')
        ->and(subscriptionOf($tenant)->plan->key)->toBe('free');

    $result = confirmTransfer(invoiceByUlid($invoice['id']), 'FT2610310001', 199000);

    expect($result['payment_status'])->toBe('applied')->and($result['invoice']['status'])->toBe('paid');
    $sub = subscriptionOf($tenant);
    expect($sub->plan->key)->toBe('starter')
        ->and($sub->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($sub->current_period_end->isSameDay(now()->addMonth()))->toBeTrue();

    // Entitlements follow the plan: the limits of Starter now apply.
    expect(actingInTenant($owner, $tenant)->getJson('/api/v1/subscription')->json('data.entitlements')['limit.shops']['limit'])->toBe(3);
});

it('applies the same bank transaction only once', function () {
    [$tenant, $owner] = tenantWithOwner();
    $id = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'starter', 'cycle' => 'monthly'])->json('data.invoice.id');
    $invoice = invoiceByUlid($id);

    $first = confirmTransfer($invoice, 'FT-SAME', 199000);
    $endAfterFirst = subscriptionOf($tenant)->current_period_end;
    $this->travel(5)->minutes();
    $second = confirmTransfer($invoice, ' FT-SAME ', 199000);

    expect($first['duplicate'])->toBeFalse()
        ->and($second['duplicate'])->toBeTrue()
        ->and(Payment::withoutGlobalScopes()->count())->toBe(1)
        ->and(subscriptionOf($tenant)->current_period_end->equalTo($endAfterFirst))->toBeTrue();
});

it('keeps an underpaid invoice open until partial transfers add up, and never applies money to a closed invoice', function () {
    [$tenant, $owner] = tenantWithOwner();
    $id = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'starter', 'cycle' => 'monthly'])->json('data.invoice.id');
    $invoice = invoiceByUlid($id);

    expect(confirmTransfer($invoice, 'FT-1', 100000)['payment_status'])->toBe('underpaid')
        ->and($invoice->refresh()->status)->toBe('pending')
        ->and(subscriptionOf($tenant)->plan->key)->toBe('free');

    expect(confirmTransfer($invoice, 'FT-2', 99000)['payment_status'])->toBe('applied')
        ->and($invoice->refresh()->status)->toBe('paid')
        ->and(subscriptionOf($tenant)->plan->key)->toBe('starter');

    // A later transfer for a paid invoice is kept for refund/reconciliation, not applied again.
    expect(confirmTransfer($invoice, 'FT-3', 199000)['payment_status'])->toBe('unapplied');
});

it('credits the unused part of a paid period when upgrading', function () {
    [$tenant, $owner] = tenantWithOwner();
    $this->freezeTime();
    onPaidPlan($tenant, 'starter', 'monthly', CarbonImmutable::now()->subDays(15));

    $quote = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/quote', ['plan' => 'pro', 'cycle' => 'monthly'])->assertOk()->json('data');

    expect($quote['kind'])->toBe('upgrade')
        ->and($quote['subtotal'])->toEqual(499000)
        ->and($quote['amount'])->toBeLessThan(499000 - 80000)
        ->and($quote['amount'])->toBeGreaterThan(499000 - 120000)
        ->and(fmod($quote['amount'], 1000))->toEqual(0.0)
        ->and($quote['credit'] + $quote['amount'])->toEqual(499000);
});

it('replaces the previous unpaid invoice when another plan is chosen', function () {
    [$tenant, $owner] = tenantWithOwner();
    $first = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'starter', 'cycle' => 'monthly'])->json('data.invoice.id');
    $second = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'pro', 'cycle' => 'yearly'])->json('data.invoice');

    expect(invoiceByUlid($first)->status)->toBe('cancelled')
        ->and($second['amount'])->toEqual(4990000)
        ->and(Invoice::withoutGlobalScopes()->where('status', 'pending')->count())->toBe(1);
});

it('refuses a downgrade while usage exceeds the new limits, and schedules it for the end of a paid period', function () {
    [$tenant, $owner] = tenantWithOwner();
    onPaidPlan($tenant, 'starter');
    TenantContext::run($tenant, fn () => Warehouse::create(['code' => 'K2', 'name' => 'Kho 2']));

    $blockers = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/quote', ['plan' => 'free', 'cycle' => 'monthly'])->json('data.downgrade_blockers');
    expect($blockers['limit.warehouses'])->toBe(['used' => 2, 'limit' => 1]);
    actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'free', 'cycle' => 'monthly'])
        ->assertStatus(422)->assertJsonValidationErrors('plan');

    TenantContext::run($tenant, fn () => Warehouse::query()->where('code', 'K2')->delete());
    actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'free', 'cycle' => 'monthly'])
        ->assertOk()->assertJsonPath('data.action', 'scheduled');

    $sub = subscriptionOf($tenant);
    expect($sub->plan->key)->toBe('starter')->and($sub->pendingPlan->key)->toBe('free');

    // At the end of the paid period the sweep applies it. Data is kept; only entitlements change.
    $this->travelTo($sub->current_period_end->copy()->addMinute());
    Artisan::call('ecso:billing:sweep');
    expect(subscriptionOf($tenant)->plan->key)->toBe('free')->and(subscriptionOf($tenant)->pending_plan_id)->toBeNull();
});

it('allows one trial per tenant and ends it on the free plan', function () {
    [$tenant, $owner] = tenantWithOwner();

    actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/trial', ['plan' => 'pro'])->assertOk()->assertJsonPath('data.status', 'trialing');
    expect(subscriptionOf($tenant)->plan->key)->toBe('pro');
    $show = actingInTenant($owner, $tenant)->getJson('/api/v1/subscription')->assertJsonPath('data.trial_available', false)->json('data');
    expect($show['entitlements']['reports.advanced']['enabled'])->toBeTrue();

    actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/trial', ['plan' => 'business'])->assertStatus(422);
    actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/trial', ['plan' => 'free'])->assertStatus(422);

    $this->travel(15)->days();
    Artisan::call('ecso:billing:sweep');
    expect(subscriptionOf($tenant)->plan->key)->toBe('free')->and(subscriptionOf($tenant)->status)->toBe('active');

    actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/trial', ['plan' => 'pro'])->assertStatus(422);
});

it('issues a renewal invoice before the period ends, extends the period when paid, and moves unpaid tenants to free after the grace period', function () {
    [$paying] = tenantWithOwner('Paying');
    [$late] = tenantWithOwner('Late');
    $start = CarbonImmutable::now()->subDays(25);
    $endPaying = onPaidPlan($paying, 'starter', 'monthly', $start)->current_period_end;
    onPaidPlan($late, 'starter', 'monthly', $start);

    Artisan::call('ecso:billing:sweep');
    $renewal = Invoice::withoutGlobalScopes()->where('tenant_id', $paying->id)->where('status', 'pending')->firstOrFail();
    expect($renewal->kind)->toBe('renewal')
        ->and(Invoice::withoutGlobalScopes()->where('tenant_id', $late->id)->where('kind', 'renewal')->count())->toBe(1);

    Artisan::call('ecso:billing:sweep'); // re-running does not issue a second invoice
    expect(Invoice::withoutGlobalScopes()->where('kind', 'renewal')->count())->toBe(2);

    confirmTransfer($renewal, 'FT-RENEW', (float) $renewal->amount);
    expect(subscriptionOf($paying)->current_period_end->equalTo($endPaying->copy()->addMonth()))->toBeTrue();

    $this->travelTo($endPaying->copy()->addHour());
    Artisan::call('ecso:billing:sweep');
    expect(subscriptionOf($paying)->status)->toBe('active')
        ->and(subscriptionOf($late)->status)->toBe('past_due')
        ->and(subscriptionOf($late)->plan->key)->toBe('starter');

    $this->travel(4)->days();
    Artisan::call('ecso:billing:sweep');
    expect(subscriptionOf($late)->plan->key)->toBe('free')->and(subscriptionOf($paying)->plan->key)->toBe('starter');
});

it('processes gateway notifications idempotently with the tenant taken from the invoice', function () {
    $gateway = new class implements PaymentGateway
    {
        public function key(): string
        {
            return 'testpay';
        }

        public function label(): string
        {
            return 'Test';
        }

        public function instructions(Invoice $invoice): array
        {
            return ['method' => 'testpay'];
        }

        public function parseNotification(string $body, array $headers): ?PaymentNotification
        {
            if (($headers['x-signature'][0] ?? null) !== hash_hmac('sha256', $body, 'secret')) {
                throw new InvalidPaymentNotification('bad signature');
            }
            $data = json_decode($body, true);

            return new PaymentNotification($data['txn'], $data['code'], (float) $data['amount'], CarbonImmutable::now(), $data);
        }
    };
    app()->instance($gateway::class, $gateway);
    app(GatewayRegistry::class)->register('testpay', $gateway::class);

    [$tenant, $owner] = tenantWithOwner();
    [$other] = tenantWithOwner('Other');
    $id = actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'starter', 'cycle' => 'monthly'])->json('data.invoice.id');
    $invoice = invoiceByUlid($id);
    app(TenantContext::class)->clear();

    $body = json_encode(['txn' => 'T1', 'code' => $invoice->transfer_code, 'amount' => 199000, 'tenant_id' => $other->id]);
    $send = fn (string $signature) => $this->call('POST', '/api/v1/billing/notifications/testpay', [], [], [], ['HTTP_X_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);

    $send('forged')->assertStatus(401);
    $send(hash_hmac('sha256', $body, 'secret'))->assertOk()->assertJsonPath('duplicate', false)->assertJsonPath('status', 'applied');
    $send(hash_hmac('sha256', $body, 'secret'))->assertOk()->assertJsonPath('duplicate', true);

    expect(subscriptionOf($tenant)->plan->key)->toBe('starter')
        ->and(subscriptionOf($other)->plan->key)->toBe('free')
        ->and(Payment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);

    // Bank transfer has no notification channel.
    $this->postJson('/api/v1/billing/notifications/bank_transfer', [])->assertNotFound();
});

it('meters imported orders per month without blocking imports over the limit', function () {
    [$tenant] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-U');
    stockIn($tenant, $variant, 10);
    $shop = fakeShop($tenant);

    fakeOrder($shop, [[$variant, 1]]);
    $order = fakeOrder($shop, [[$variant, 1]]);
    // Re-delivery of the same order is not counted twice.
    app(FakeMarketplace::class)->setStatus($order->external_order_id, 'fake_shipped');

    expect(TenantContext::run($tenant, fn () => app(UsageService::class)->current(UsageService::ORDERS)))->toBe(2);
});

it('restricts billing management to members with billing permissions', function () {
    [$tenant] = tenantWithOwner();
    $staff = addMember($tenant, Role::ORDER_STAFF);

    actingInTenant($staff, $tenant)->postJson('/api/v1/subscription/change', ['plan' => 'starter', 'cycle' => 'monthly'])->assertForbidden();
});

it('keeps tenants from seeing each other\'s invoices', function () {
    [$a, $ownerA] = tenantWithOwner('A');
    [$b, $ownerB] = tenantWithOwner('B');
    $id = actingInTenant($ownerA, $a)->postJson('/api/v1/subscription/change', ['plan' => 'starter', 'cycle' => 'monthly'])->json('data.invoice.id');

    actingInTenant($ownerB, $b)->getJson("/api/v1/invoices/{$id}")->assertNotFound();
    actingInTenant($ownerB, $b)->postJson("/api/v1/invoices/{$id}/cancel")->assertNotFound();
    actingInTenant($ownerB, $b)->getJson('/api/v1/invoices')->assertJsonCount(0, 'data');
});

it('gives super admins MRR and invoices, and nobody else', function () {
    [$tenant, $owner] = tenantWithOwner();
    onPaidPlan($tenant, 'pro', 'yearly');
    [$t2] = tenantWithOwner('Monthly');
    onPaidPlan($t2, 'starter');

    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/admin/billing/summary')->assertForbidden();
    $this->getJson('/api/v1/admin/invoices')->assertForbidden();

    Sanctum::actingAs(superAdmin());
    $summary = $this->getJson('/api/v1/admin/billing/summary')->assertOk()->json('data');
    expect($summary['mrr'])->toEqualWithDelta(round(4990000 / 12, 2) + 199000, 0.01)
        ->and($summary['paying_tenants'])->toBe(2);
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.mrr', $summary['mrr']);
});
