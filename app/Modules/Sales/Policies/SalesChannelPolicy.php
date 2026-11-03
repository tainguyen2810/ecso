<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

class SalesChannelPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('orders.view');
    }

    public function create(User $user): bool
    {
        return $this->allows('settings.manage');
    }

    public function update(User $user): bool
    {
        return $this->allows('settings.manage');
    }

    public function delete(User $user): bool
    {
        return $this->allows('settings.manage');
    }
}
