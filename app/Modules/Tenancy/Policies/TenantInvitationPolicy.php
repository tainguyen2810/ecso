<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\TenantInvitation;

class TenantInvitationPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('members.manage');
    }

    public function create(User $user): bool
    {
        return $this->allows('members.manage');
    }

    public function delete(User $user, TenantInvitation $invitation): bool
    {
        return $this->allows('members.manage');
    }
}
