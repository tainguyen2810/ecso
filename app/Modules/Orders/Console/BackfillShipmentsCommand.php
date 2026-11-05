<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Services\ShipmentSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives existing orders their shipment (vận đơn): every order with a tracking number or that went out with a carrier.
 * Only inserts into shipments / shipment_events, never changes an order. Safe to run again: orders that already have
 * a shipment are skipped.
 */
class BackfillShipmentsCommand extends Command
{
    protected $signature = 'ecso:backfill-shipments {--tenant= : Chỉ chạy cho một doanh nghiệp (id)}';

    protected $description = 'Tạo vận đơn cho các đơn cũ đã có mã vận đơn hoặc đã giao cho hãng (chỉ thêm, không sửa đơn)';

    public function handle(ShipmentSync $sync): int
    {
        $created = 0;
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            $created += TenantContext::run($tenant, function () use ($sync) {
                $count = 0;
                Order::query()
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shipments')->whereColumn('shipments.order_id', 'orders.id'))
                    ->where(fn ($q) => $q->whereNotNull('tracking_number')->orWhereIn('status', [...OrderStatus::SHIPPED, OrderStatus::CANCELLED, OrderStatus::RETURN_REQUESTED, OrderStatus::RETURNED]))
                    ->orderBy('id')
                    ->chunkById(200, function ($orders) use ($sync, &$count) {
                        foreach ($orders as $order) {
                            DB::transaction(function () use ($sync, $order, &$count) {
                                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                                if ($locked && ! Shipment::query()->where('order_id', $locked->id)->exists() && $sync->apply($locked)) {
                                    $count++;
                                }
                            });
                        }
                    });

                return $count;
            });
        }

        $this->info("Đã tạo {$created} vận đơn.");

        return self::SUCCESS;
    }
}
