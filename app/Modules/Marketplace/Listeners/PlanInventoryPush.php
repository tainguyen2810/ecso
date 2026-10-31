<?php

namespace App\Modules\Marketplace\Listeners;

use App\Modules\Inventory\Events\AvailableStockChanged;
use App\Modules\Marketplace\Events\ListingMappingChanged;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Services\InventorySyncPlanner;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Throwable;

class PlanInventoryPush
{
    public function __construct(private readonly InventorySyncPlanner $planner) {}

    public function handle(AvailableStockChanged|ListingMappingChanged $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);
        if (! $tenant) {
            return;
        }

        TenantContext::run($tenant, function () use ($event) {
            $variantIds = $event instanceof AvailableStockChanged
                ? $event->variantIds
                : ListingVariant::query()->whereIn('id', $event->listingVariantIds)->whereNotNull('product_variant_id')->pluck('product_variant_id')->all();

            if ($variantIds === []) {
                return;
            }
            try {
                $this->planner->plan(array_values(array_unique($variantIds)));
            } catch (Throwable $e) {
                // Never fail the stock change that triggered this; the shop's push-stock / reconcile recovers.
                report($e);
            }
        });
    }
}
