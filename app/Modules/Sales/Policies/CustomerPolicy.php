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
        return $this->allows('customers.delete');
    }

    public function import(User $user): bool
    {
        return $this->allows('customers.import');
    }

    public function adjustDebt(User $user): bool
    {
        return $this->allows('customers.debt_adjust');
    }

    public function export(User $user): bool
    {
        return $this->allows('customers.export');
    }
}
