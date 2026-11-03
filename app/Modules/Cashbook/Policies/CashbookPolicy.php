<?php

namespace App\Modules\Cashbook\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

/** Receipt and payment vouchers and their types: anyone with finance access reads them, finance.vouchers writes. */
class CashbookPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('finance.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('finance.view');
    }

    public function create(User $user): bool
    {
        return $this->allows('finance.vouchers');
    }

    public function update(User $user): bool
    {
        return $this->allows('finance.vouchers');
    }

    public function void(User $user): bool
    {
        return $this->allows('finance.vouchers');
    }
}
