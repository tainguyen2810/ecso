<?php

namespace App\Modules\Orders\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

class ReturnPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('returns.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('returns.view');
    }

    /** Recording a return/refund request is order handling. */
    public function create(User $user): bool
    {
        return $this->allows('orders.update');
    }

    /** Approving, receiving and inspecting goods (inspection adds stock back). */
    public function inspect(User $user): bool
    {
        return $this->allows('returns.inspect');
    }
}
