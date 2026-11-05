<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gives existing orders their shipment (vận đơn): every order with a tracking number or that went out with a carrier.
 * Only inserts into shipments / shipment_events, never changes an order. Safe to run again: orders that already have
 * a shipment are skipped. Called by the migration (also from the web installer) and by ecso:backfill-shipments.
 */
class ShipmentBackfill
{
    public function __construct(private readonly ShipmentSync $sync) {}

    /** @return int shipments created */
    public function run(?int $tenantId = null): int
    {
        $created = 0;
        foreach (Tenant::query()->when($tenantId, fn ($q, $id) => $q->whereKey($id))->get() as $tenant) {
            $created += TenantContext::run($tenant, fn () => $this->forTenant());
        }

        return $created;
    }

    private function forTenant(): int
    {
        $count = 0;
        Order::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shipments')->whereColumn('shipments.order_id', 'orders.id'))
            ->where(fn ($q) => $q->whereNotNull('tracking_number')->orWhereIn('status', [...OrderStatus::SHIPPED, OrderStatus::CANCELLED]))
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, &$count) {
                        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                        if ($locked && ! Shipment::query()->where('order_id', $locked->id)->exists() && $this->sync->apply($locked)) {
                            $count++;
                        }
                    });
                }
            });

        return $count;
    }
}
