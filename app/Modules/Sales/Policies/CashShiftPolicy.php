<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\CashShift;
use App\Modules\Tenancy\Policies\PermissionPolicy;

/** Cashiers run their own shifts; orders.shifts sees and closes everyone's (a forgotten shift, a handover). */
class CashShiftPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('orders.create') || $this->allows('orders.shifts');
    }

    public function viewAll(User $user): bool
    {
        return $this->allows('orders.shifts');
    }

    public function view(User $user, CashShift $shift): bool
    {
        return $this->allows('orders.shifts') || ($shift->user_id === $user->id && $this->allows('orders.create'));
    }

    public function create(User $user): bool
    {
        return $this->allows('orders.create');
    }

    public function update(User $user, CashShift $shift): bool
    {
        return $this->view($user, $shift);
    }
}
