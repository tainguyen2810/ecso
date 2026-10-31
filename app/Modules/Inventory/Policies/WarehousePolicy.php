<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

class WarehousePolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('inventory.view');
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
