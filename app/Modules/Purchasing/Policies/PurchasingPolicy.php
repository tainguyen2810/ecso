<?php

namespace App\Modules\Purchasing\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

/** Suppliers, purchase orders, supplier returns and supplier payments. */
class PurchasingPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('purchasing.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('purchasing.view');
    }

    public function create(User $user): bool
    {
        return $this->allows('purchasing.manage');
    }

    public function update(User $user): bool
    {
        return $this->allows('purchasing.manage');
    }

    public function delete(User $user): bool
    {
        return $this->allows('purchasing.manage');
    }

    /** Warehouse staff receive goods against an order without being able to create or price orders. */
    public function receive(User $user): bool
    {
        return $this->allows('purchasing.manage') || $this->allows('inventory.receive');
    }

    public function pay(User $user): bool
    {
        return $this->allows('purchasing.payment');
    }
}
