<?php

namespace App\Modules\Shipping\Console;

use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Connectors\CarrierRegistry;
use App\Modules\Shipping\Models\CarrierAccount;
use App\Modules\Shipping\Services\CarrierEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/** Asks carriers again about parcels that heard nothing for a while: a lost or never-set-up webhook loses nothing. */
class TrackCarrierShipmentsCommand extends Command
{
    protected $signature = 'ecso:carriers:track {--limit=200 : parcels per account per run}';

    protected $description = 'Cập nhật trạng thái vận đơn đã đẩy qua hãng nhưng lâu chưa có tin';

    public function handle(CarrierRegistry $carriers, CarrierEventApplier $applier, TenantContext $context): int
    {
        $since = now()->subMinutes((int) config('shipping.track_after_minutes'));
        $checked = 0;
        $updated = 0;
        $accounts = CarrierAccount::query()->withoutGlobalScopes()->where('status', CarrierAccount::CONNECTED)->get();
        foreach ($accounts as $account) {
            if (! $carriers->has($account->carrier)) {
                continue;
            }
            $context->set(Tenant::query()->findOrFail($account->tenant_id));
            try {
                $stale = Shipment::query()->where('carrier_account_id', $account->id)->whereNotNull('tracking_number')
                    ->whereNotIn('status', [...ShipmentStatus::FINAL, ShipmentStatus::PENDING])
                    ->where(fn ($q) => $q->whereNull('last_event_at')->orWhere('last_event_at', '<', $since))
                    ->where(fn ($q) => $q->whereNull('tracked_at')->orWhere('tracked_at', '<', $since))
                    ->orderBy('tracked_at')->limit((int) $this->option('limit'))->get();
                foreach ($stale as $shipment) {
                    $checked++;
                    try {
                        $updated += $applier->apply($account, $carriers->get($account->carrier)->track($account, $shipment)) > 0 ? 1 : 0;
                        // Asked even when nothing changed, so the same parcel is not asked again on the next run.
                        $shipment->forceFill(['tracked_at' => now()])->saveQuietly();
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            } finally {
                $context->clear();
            }
        }
        $this->info("Đã hỏi {$checked} vận đơn, {$updated} vận đơn có tin mới.");

        return self::SUCCESS;
    }
}
