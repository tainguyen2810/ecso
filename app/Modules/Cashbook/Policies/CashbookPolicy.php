<?php

namespace App\Modules\Cashbook\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

/**
 * Receipt and payment vouchers, their types and funds: anyone with finance access reads them (fund balances too),
 * finance.vouchers writes vouchers and finance.vouchers.void voids them, finance.close counts funds and closes the
 * books, finance.accounts manages funds and transfers.
 */
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
        return $this->allows('finance.vouchers.void');
    }

    /** Counting funds, closing and reopening the books. */
    public function close(User $user): bool
    {
        return $this->allows('finance.close');
    }

    /** Funds and transfers between them. */
    public function manage(User $user): bool
    {
        return $this->allows('finance.accounts');
    }
}
