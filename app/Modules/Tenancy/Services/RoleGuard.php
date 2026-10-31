<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Privilege-escalation rules for member management:
 *  - nobody changes their own membership,
 *  - the owner role is never assigned or modified here (ownership transfer is a separate flow),
 *  - an actor may only assign or manage roles whose permissions are a subset of their own.
 */
class RoleGuard
{
    public function __construct(private readonly TenantContext $context) {}

    public function canAssign(Role $role): bool
    {
        if ($role->isOwner()) {
            return false;
        }

        if ($role->tenant_id !== null && $role->tenant_id !== $this->context->id()) {
            return false;
        }

        return $this->isSubsetOfActor($role);
    }

    public function canManage(TenantUser $target): bool
    {
        $actor = $this->context->membership();

        if ($actor === null || $actor->is($target) || $target->role->isOwner()) {
            return false;
        }

        return $this->isSubsetOfActor($target->role);
    }

    private function isSubsetOfActor(Role $role): bool
    {
        return array_diff($role->permissionKeys(), $this->context->permissions()) === [];
    }
}
