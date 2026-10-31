<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Tenancy\Policies\PermissionPolicy;

/**
 * Stock, receipts, adjustments and transfers.
 */
class InventoryPolicy extends PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows('inventory.view');
    }

    public function view(User $user): bool
    {
        return $this->allows('inventory.view');
    }

    public function receive(User $user): bool
    {
        return $this->allows('inventory.receive');
    }

    public function adjust(User $user): bool
    {
        return $this->allows('inventory.adjust');
    }

    public function approve(User $user): bool
    {
        return $this->allows('inventory.adjust.approve');
    }

    /** The creator may withdraw their own pending adjustment; approvers may cancel any. */
    public function cancel(User $user, InventoryAdjustment $adjustment): bool
    {
        return ($adjustment->created_by === $user->id && $this->allows('inventory.adjust')) || $this->allows('inventory.adjust.approve');
    }

    public function transfer(User $user): bool
    {
        return $this->allows('inventory.transfer');
    }

    /** Safety stock drives what is published to marketplaces. */
    public function configure(User $user): bool
    {
        return $this->allows('inventory.adjust.approve');
    }
}
