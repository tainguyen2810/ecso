<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

class TenantPolicy extends PermissionPolicy
{
    public function update(User $user, Tenant $tenant): bool
    {
        return app(TenantContext::class)->tenant()->is($tenant) && $this->allows('settings.manage');
    }
}
