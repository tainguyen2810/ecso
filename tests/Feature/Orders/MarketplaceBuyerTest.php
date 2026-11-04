<?php

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerIdentity;
use App\Modules\Tenancy\Support\TenantContext;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 20);
    $this->shop = fakeShop($this->tenant);
    $this->as = fn () => actingInTenant($this->owner, $this->tenant);
    $this->in = fn (callable $fn) => TenantContext::run($this->tenant, $fn);
    $buyer = fn (string $id, string $user, string $phone) => ['buyer' => ['name' => 'Ng***n V** A*', 'phone' => $phone, 'address' => '***', 'user_id' => $id, 'username' => $user]];
    $this->order = fn (string $id, string $user, string $phone = '84*****567', array $extra = []) => fakeOrder($this->shop, [[$this->shirt, 1]], 'fake_ready', $buyer($id, $user, $phone) + $extra);
});

it('knows a returning marketplace buyer by their account, keeping only a name on the card', function () {
    $first = ($this->order)('700100001', 'an.nguyen');
    // Same account, another masked phone: same card. Another account: another card (never matched by name).
    $second = ($this->order)('700100001', 'an.nguyen', '84*****999');
    $other = ($this->order)('700100002', 'an.nguyen.2');

    expect($first->customer_id)->not->toBeNull()->toBe($second->customer_id)->not->toBe($other->customer_id);
    $card = ($this->in)(fn () => Customer::query()->find($first->customer_id));
    expect($card->name)->toBe('an.nguyen')->and($card->phone)->toBeNull()->and($card->address)->toBeNull()
        ->and(($this->in)(fn () => CustomerIdentity::query()->where('customer_id', $card->id)->value('label')))->toBe('an.nguyen');

    // The customer page counts the marketplace orders in what they bought, never in what they owe.
    $show = ($this->as)()->getJson("/api/v1/customers/{$card->ulid}")->assertOk()->json('data');
    expect((int) $show['orders_count'])->toBe(2)->and($show['debt'])->toBe('0.00');
    ($this->as)()->getJson("/api/v1/customers/{$card->ulid}/links")->assertOk()->assertJsonPath('data.identities.0.external_id', '700100001');
    // Another business with the same marketplace buyer gets its own card.
    [$tenantB] = tenantWithOwner('Shop B');
    expect(TenantContext::run($tenantB, fn () => Customer::query()->count()))->toBe(0);
});

it('keeps the card a seller made, and leaves orders without a buyer account unlinked', function () {
    $own = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Chị An', 'phone' => '0901234567'])->json('data.id');
    $first = ($this->order)('700100009', 'chi.an');
    $card = ($this->in)(fn () => Customer::query()->find($first->customer_id));
    // Merged by hand into the seller's card: the next order of that account lands there, and the card keeps its name.
    ($this->as)()->postJson("/api/v1/customers/{$own}/merge", ['source' => $card->ulid])->assertSuccessful();
    $next = ($this->order)('700100009', 'chi.an');
    expect(($this->in)(fn () => Customer::query()->find($next->customer_id)))->ulid->toBe($own)->name->toBe('Chị An');

    $anonymous = fakeOrder($this->shop, [[$this->shirt, 1]], 'fake_ready', ['buyer' => ['name' => 'K***', 'phone' => '84*****000', 'address' => '***']]);
    expect($anonymous->customer_id)->toBeNull();
});
