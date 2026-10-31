<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\TenantUser;

class TenantUserPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('members.view');
    }

    public function update(User $user, TenantUser $member): bool
    {
        return $this->allows('members.manage');
    }

    public function delete(User $user, TenantUser $member): bool
    {
        return $this->allows('members.manage');
    }
}
