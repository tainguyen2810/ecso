<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Loyalty\LoyaltyService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\LoyaltyEntry;
use App\Modules\Sales\Models\LoyaltyTier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly run of Tích điểm (C5): expires points past their date and puts every customer in the tier their spending
 * of the last 12 months reaches (moving down too, which a purchase never does).
 */
class LoyaltyNightlyCommand extends Command
{
    protected $signature = 'ecso:loyalty:nightly {--tenant= : Only this tenant id}';

    protected $description = 'Hết hạn điểm và xét lại hạng khách theo chi tiêu 12 tháng.';

    public function handle(LoyaltyService $loyalty): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use ($loyalty, $tenant) {
                $expired = 0;
                $due = LoyaltyEntry::query()->where('points', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())->distinct()->pluck('customer_id');
                foreach (Customer::query()->whereIn('id', $due)->where('loyalty_points', '>', 0)->get() as $customer) {
                    $expired += DB::transaction(fn () => $loyalty->expire($customer));
                }
                $moved = 0;
                if (LoyaltyTier::query()->exists()) {
                    Customer::query()->where(fn ($q) => $q->whereNotNull('loyalty_tier_id')->orWhereHas('orders'))->select('id', 'loyalty_tier_id')
                        ->chunkById(500, function ($customers) use ($loyalty, &$moved) {
                            foreach ($customers as $c) {
                                $before = $c->loyalty_tier_id;
                                $moved += $loyalty->retier($c->id)?->id !== $before ? 1 : 0;
                            }
                        });
                }
                if ($expired > 0 || $moved > 0) {
                    $this->line("{$tenant->name}: {$expired} điểm hết hạn, {$moved} khách đổi hạng");
                }
            });
        }

        return self::SUCCESS;
    }
}
