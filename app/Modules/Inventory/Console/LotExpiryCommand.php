<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Events\AvailableStockChanged;
use App\Modules\Inventory\Services\LotLedger;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lots stop being sold on the day they pass their product's stop-selling threshold, without any stock movement.
 * Run just after midnight: the SKUs with lots that crossed it in the last days get their marketplace stock pushed
 * again (the push itself recomputes sellable stock, so a repeat is harmless).
 */
class LotExpiryCommand extends Command
{
    protected $signature = 'ecso:inventory:lot-expiry {--tenant= : Only this tenant id}';

    protected $description = 'Đẩy lại tồn lên sàn cho SKU có lô vừa hết hạn / tới ngưỡng ngừng bán.';

    public function handle(): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use ($tenant) {
                $today = LotLedger::today();
                $ids = DB::table('stock_lots as l')
                    ->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->where('l.tenant_id', $tenant->id)->where('p.track_lots', true)
                    ->whereRaw('l.on_hand > l.reserved')->whereNotNull('l.expiry_date')
                    ->whereRaw('l.expiry_date < DATE_ADD(?, INTERVAL COALESCE(p.expiry_block_days, 0) DAY)', [$today])
                    ->whereRaw('l.expiry_date >= DATE_ADD(?, INTERVAL COALESCE(p.expiry_block_days, 0) - 3 DAY)', [$today])
                    ->distinct()->pluck('l.product_variant_id')->map(fn ($id) => (int) $id)->all();
                if ($ids !== []) {
                    AvailableStockChanged::dispatch($tenant->id, $ids);
                    $this->line("{$tenant->name}: ".count($ids).' SKU có lô vừa ngừng bán');
                }
            });
        }

        return self::SUCCESS;
    }
}
