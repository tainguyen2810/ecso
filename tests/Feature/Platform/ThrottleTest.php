<?php

it('counts each throttled route on its own, so heavy use of one does not lock out another', function () {
    [$tenant, $owner] = tenantWithOwner();

    for ($i = 0; $i < 10; $i++) {
        actingInTenant($owner, $tenant)->getJson('/api/v1/customers/export')->assertSuccessful();
    }
    actingInTenant($owner, $tenant)->getJson('/api/v1/customers/export')->assertStatus(429);

    // Another route with the same limit still has its own allowance.
    actingInTenant($owner, $tenant)->getJson('/api/v1/products/export')->assertSuccessful();
});
