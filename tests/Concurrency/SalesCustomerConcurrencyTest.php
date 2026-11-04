<?php

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two counter sales for the same new phone at the same moment: the second one must reuse the customer the first one
 * just committed instead of failing on the unique phone. The other request is played by a second connection that
 * commits the customer between this request's phone lookup and its insert.
 */
it('reuses the customer another request committed a moment ago with the same phone', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    config(['database.connections.race' => config('database.connections.mysql')]);
    $hash = PhoneNumber::hash('0987654321');

    Customer::creating(function (Customer $c) use ($hash, $tenant) {
        if ($c->phone_hash === $hash && ! DB::connection('race')->table('customers')->where('phone_hash', $hash)->exists()) {
            DB::connection('race')->table('customers')->insert([
                'ulid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'code' => 'KH-RACE', 'name' => 'Đã tạo', 'phone_hash' => $hash,
                'phone_last4' => '4321', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $order = createSalesOrder($owner, $tenant, [[$variant, 1]], ['customer' => ['name' => 'Khách mới', 'phone' => '0987654321']])->assertCreated()->json('data');

    expect(Customer::withoutGlobalScopes()->where('phone_hash', $hash)->pluck('code')->all())->toBe(['KH-RACE'])
        ->and($order['customer']['code'] ?? null)->toBe('KH-RACE');
});
