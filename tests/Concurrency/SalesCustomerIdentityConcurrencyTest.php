<?php

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerIdentity;
use App\Modules\Sales\Services\CustomerResolver;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two orders of the same new marketplace buyer at once: the second one must drop the card it was making and take the
 * card the first one committed with that buyer account, not leave a duplicate card. The first order is played by a
 * second connection that commits its card and account between this order's lookup and its insert.
 */
it('takes the card another order committed for the same buyer account', function () {
    [$tenant] = tenantWithOwner();
    $shop = fakeShop($tenant);
    config(['database.connections.race' => config('database.connections.mysql')]);

    CustomerIdentity::creating(function (CustomerIdentity $identity) use ($tenant, $shop) {
        if (DB::connection('race')->table('customer_identities')->where('external_id', $identity->external_id)->exists()) {
            return;
        }
        $id = DB::connection('race')->table('customers')->insertGetId([
            'ulid' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'code' => 'KH-RACE', 'name' => 'Đã tạo', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('race')->table('customer_identities')->insert([
            'tenant_id' => $tenant->id, 'customer_id' => $id, 'source' => 'shopee', 'marketplace_account_id' => $shop->id,
            'external_id' => $identity->external_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $customer = TenantContext::run($tenant, fn () => DB::transaction(fn () => app(CustomerResolver::class)->resolve(
        ['name' => 'buyer.one'], ['source' => 'shopee', 'account_id' => $shop->id, 'external_id' => '900001'], 'shopee',
    )));

    expect($customer->code)->toBe('KH-RACE')
        ->and(Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('code')->all())->toBe(['KH-RACE'])
        ->and(DB::table('audit_logs')->where('action', 'customer.created')->count())->toBe(0);
});
