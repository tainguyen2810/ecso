<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Tenancy\Support\TenantContext;

/**
 * Base for tenant policies: every ability maps to a permission key of the current membership.
 * Super admins get no implicit access to tenant data (MULTI-TENANCY.md §6).
 */
abstract class PermissionPolicy
{
    protected function allows(string $permission): bool
    {
        $context = app(TenantContext::class);

        return $context->has() && $context->can($permission);
    }
}
