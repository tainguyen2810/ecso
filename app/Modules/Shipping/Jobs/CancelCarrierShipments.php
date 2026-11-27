<?php

namespace App\Modules\Shipping\Jobs;

use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Services\CarrierShipmentService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Hủy đơn thì tự hủy vận đơn bên hãng": the parcels the carrier had not picked up when the order was cancelled are
 * cancelled at the carrier too. A carrier that refuses (picked up in between) is logged; the parcel then comes back.
 */
class CancelCarrierShipments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param  array<int, int>  $shipmentIds */
    public function __construct(public readonly int $tenantId, public readonly array $shipmentIds) {}

    public function handle(CarrierShipmentService $carriers): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        TenantContext::run($tenant, function () use ($carriers) {
            foreach (Shipment::query()->whereKey($this->shipmentIds)->get() as $shipment) {
                try {
                    $carriers->cancelAtCarrier($shipment);
                } catch (Throwable $e) {
                    Log::warning('auto cancel carrier shipment failed', ['tenant' => $this->tenantId, 'shipment' => $shipment->id, 'error' => $e->getMessage()]);
                }
            }
        });
    }
}
