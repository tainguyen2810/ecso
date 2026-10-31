<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Tenancy\Events\TenantCreated;

class CreateDefaultWarehouse
{
    public function __construct(private readonly WarehouseService $warehouses) {}

    public function handle(TenantCreated $event): void
    {
        $this->warehouses->createDefault();
    }
}
