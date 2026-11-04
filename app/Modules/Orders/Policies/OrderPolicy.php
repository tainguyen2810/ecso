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

    public function cancel(User $user): bool
    {
        return $this->allows('orders.cancel');
    }

    /** Void a payment or refund typed by mistake. */
    public function voidPayment(User $user): bool
    {
        return $this->allows('orders.payment.void');
    }

    public function assign(User $user): bool
    {
        return $this->allows('orders.assign');
    }

    public function print(User $user): bool
    {
        return $this->allows('orders.print');
    }

    public function create(User $user): bool
    {
        return $this->allows('orders.create');
    }

    public function pay(User $user): bool
    {
        return $this->allows('orders.payment');
    }

    /** Sell at another price or with a discount when Cấu hình does not let staff do it. */
    public function changePrice(User $user): bool
    {
        return $this->allows('orders.price');
    }
}
