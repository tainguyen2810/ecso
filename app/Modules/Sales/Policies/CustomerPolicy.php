<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

class CustomerPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('customers.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('customers.view');
    }

    public function create(User $user): bool
    {
        return $this->allows('customers.manage');
    }

    public function update(User $user): bool
    {
        return $this->allows('customers.manage');
    }

    public function delete(User $user): bool
    {
        return $this->allows('customers.manage');
    }
}
