<?php

use App\Modules\Access\Models\Role;
use App\Modules\Sales\Models\CustomerMerge;
use App\Modules\Sales\Services\CustomerResolver;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
    $this->variant = makeVariant($this->tenant);
    stockIn($this->tenant, $this->variant, 50);
    $this->card = fn (array $data) => ($this->as)()->postJson('/api/v1/customers', $data)->assertCreated()->json('data.id');
});

it('lists cards that share an email, a tax code or a name, never merging them by itself', function () {
    $a = ($this->card)(['name' => 'Lan', 'email' => 'Lan@Example.com', 'phone' => '0901000001']);
    $b = ($this->card)(['name' => 'Chị Lan', 'email' => 'lan@example.com ']);
    ($this->card)(['name' => 'Minh', 'tax_code' => '0101234567']);
    ($this->card)(['name' => 'Cty Minh', 'tax_code' => '0101234567']);
    ($this->card)(['name' => 'Hoa']);
    ($this->card)(['name' => 'hoa ']);

    $groups = collect(($this->as)()->getJson('/api/v1/customers/duplicates')->assertOk()->json('data'));
    expect($groups->pluck('reason')->all())->toBe(['email', 'tax_code', 'name'])
        ->and($groups->first()['customers'])->toHaveCount(2)
        ->and(DB::table('customers')->count())->toBe(6);
    expect(collect($groups->first()['customers'])->pluck('id')->sort()->values()->all())->toBe(collect([$a, $b])->sort()->values()->all());

    ($this->as)(addMember($this->tenant, Role::ORDER_STAFF))->getJson('/api/v1/customers/duplicates')->assertForbidden();
});

it('merges a duplicate: every document moves, nothing is lost, the debt is counted once', function () {
    $keep = ($this->card)(['name' => 'Chị Lan', 'phone' => '0901000001', 'address' => '12 Lê Lợi', 'tags' => ['VIP']]);
    $dup = ($this->card)(['name' => 'Lan Nguyễn', 'phone' => '0902000002', 'email' => 'lan@example.com', 'address' => '99 Phố Huế', 'tags' => ['Sỉ'], 'tax_code' => '0101']);
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 100000]], ['customer_id' => $keep, 'stage' => 'completed'])->assertCreated();
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 50000]], ['customer_id' => $dup, 'stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 20000]]])->assertCreated();
    ($this->as)()->postJson("/api/v1/customers/{$dup}/notes", ['body' => 'Hay gọi buổi sáng'])->assertCreated();
    ($this->as)()->postJson("/api/v1/customers/{$dup}/debt-entries", ['kind' => 'opening', 'amount' => 30000, 'reason' => 'Nợ cũ'])->assertCreated();

    $preview = ($this->as)()->getJson("/api/v1/customers/{$keep}/merge-preview?source={$dup}")->assertOk()->json('data');
    expect($preview['moves'])->toMatchArray(['orders' => 1, 'payments' => 1, 'notes' => 1, 'debt_entries' => 1])
        ->and(collect($preview['differences'])->pluck('field')->all())->toContain('name', 'phone', 'address');

    ($this->as)()->postJson("/api/v1/customers/{$keep}/merge", ['source' => $keep])->assertUnprocessable();
    ($this->as)()->postJson("/api/v1/customers/{$keep}/merge", ['source' => $dup, 'take' => ['name']])->assertCreated();

    $card = ($this->as)()->getJson("/api/v1/customers/{$keep}")->assertOk()->json('data');
    expect($card)->toMatchArray(['name' => 'Lan Nguyễn', 'phone' => '0901000001', 'address' => '12 Lê Lợi', 'email' => 'lan@example.com', 'tax_code' => '0101', 'orders_count' => 2, 'debt' => '160000.00'])
        ->and($card['tags'])->toBe(['VIP', 'Sỉ'])
        ->and(collect($card['addresses'])->pluck('address')->all())->toBe(['99 Phố Huế'])
        ->and(collect($card['addresses'])->first()['phone'])->toBe('0902000002')
        ->and($card['notes'])->toHaveCount(1);
    ($this->as)()->getJson("/api/v1/customers/{$dup}")->assertNotFound();

    app(TenantContext::class)->set($this->tenant);
    $merge = CustomerMerge::query()->sole();
    expect($merge->snapshot)->toMatchArray(['name' => 'Lan Nguyễn', 'phone' => '0902000002'])
        ->and(($this->as)()->getJson("/api/v1/customers/{$keep}/links")->json('data.merges.0.code'))->toBe($merge->merged_code);
    $actions = collect(($this->as)()->getJson("/api/v1/customers/{$keep}/activity")->json('data'))->pluck('action');
    expect($actions)->toContain('customer.merged')->toContain('customer.debt_opening');

    // The phone given up by the merged card is free again for a new buyer.
    ($this->card)(['name' => 'Người mới', 'phone' => '0902000002']);
});

it('takes the phone from the duplicate and keeps the old contact as a delivery address', function () {
    $keep = ($this->card)(['name' => 'An', 'phone' => '0901000001', 'address' => '1 Trần Phú']);
    $dup = ($this->card)(['name' => 'An', 'phone' => '0902000002', 'address' => '2 Hùng Vương']);
    ($this->as)()->postJson("/api/v1/customers/{$keep}/merge", ['source' => $dup, 'take' => ['phone', 'address']])->assertCreated();

    $card = ($this->as)()->getJson("/api/v1/customers/{$keep}")->json('data');
    expect($card)->toMatchArray(['phone' => '0902000002', 'address' => '2 Hùng Vương'])
        ->and(collect($card['addresses'])->pluck('address')->all())->toBe(['1 Trần Phú']);
});

it('never merges cards of another business', function () {
    $keep = ($this->card)(['name' => 'An']);
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $theirs = actingInTenant($otherOwner, $other)->postJson('/api/v1/customers', ['name' => 'An'])->json('data.id');

    ($this->as)()->postJson("/api/v1/customers/{$keep}/merge", ['source' => $theirs])->assertNotFound();
    actingInTenant($otherOwner, $other)->getJson("/api/v1/customers/{$theirs}")->assertOk();
});

it('recognises a channel buyer by store id, then phone, then email, and never blanks the card', function () {
    $resolver = app(CustomerResolver::class);
    app(TenantContext::class)->set($this->tenant);
    $woo = ['source' => 'woocommerce', 'account_id' => 7, 'external_id' => '42', 'label' => 'lan_shop'];

    $lan = $resolver->resolve(['name' => 'Lan', 'phone' => '0901000001', 'email' => null, 'address' => '12 Lê Lợi'], $woo, 'website');
    expect($lan->source)->toBe('website');
    // Same store id with a new phone and no address: the same card, its address kept.
    expect($resolver->resolve(['name' => 'Lan', 'phone' => '0903000003', 'address' => ''], $woo)->id)->toBe($lan->id)
        ->and($lan->fresh()->address)->toBe('12 Lê Lợi');
    // Another store, same phone.
    expect($resolver->resolve(['name' => 'Lan N.', 'phone' => '+84901000001', 'email' => 'lan@example.com'], ['source' => 'woocommerce', 'account_id' => 8, 'external_id' => '1'])->id)->toBe($lan->id)
        ->and($lan->fresh()->email)->toBe('lan@example.com');
    // Email only.
    expect($resolver->resolve(['name' => 'Lan', 'email' => 'LAN@example.com'])->id)->toBe($lan->id);
    // Same name, nothing else: a new card.
    expect($resolver->resolve(['name' => 'Lan', 'phone' => '0904000004'])->id)->not->toBe($lan->id);
    // Nothing to recognise the buyer by again: no card.
    expect($resolver->resolve(['name' => 'Khách vãng lai']))->toBeNull();
    expect(($this->as)()->getJson("/api/v1/customers/{$lan->ulid}/links")->json('data.identities'))->toHaveCount(2);
});
