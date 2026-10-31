<?php

namespace App\Modules\Marketplace\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Policies\PermissionPolicy;

/** Connected shops and listing mapping. */
class MarketplacePolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('marketplace.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('marketplace.view');
    }

    public function connect(User $user): bool
    {
        return $this->allows('marketplace.connect');
    }

    /** Linking listings to internal SKUs changes what is reserved and published: same permission as connecting. */
    public function map(User $user): bool
    {
        return $this->allows('marketplace.connect');
    }
}
