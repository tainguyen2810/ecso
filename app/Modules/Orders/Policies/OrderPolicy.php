<?php

namespace App\Modules\Orders\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

class OrderPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('orders.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('orders.view');
    }

    public function update(User $user): bool
    {
        return $this->allows('orders.update');
    }

    public function assign(User $user): bool
    {
        return $this->allows('orders.assign');
    }

    public function print(User $user): bool
    {
        return $this->allows('orders.print');
    }
}
