<?php

namespace App\Modules\Tenancy\Events;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched synchronously inside the new tenant's context and transaction, so listeners can seed tenant data.
 */
class TenantCreated
{
    use Dispatchable;

    public function __construct(public readonly Tenant $tenant) {}
}
