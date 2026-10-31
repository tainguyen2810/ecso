<?php

namespace App\Modules\Catalog\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

/** Products, variants, categories and brands share the products.* permissions. */
class ProductPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('products.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('products.view');
    }

    public function create(User $user): bool
    {
        return $this->allows('products.manage');
    }

    public function update(User $user): bool
    {
        return $this->allows('products.manage');
    }

    public function delete(User $user): bool
    {
        return $this->allows('products.manage');
    }
}
