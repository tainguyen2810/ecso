<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Notifications\FeeAlertNotification;
use App\Modules\Marketplace\Services\FeeAnalyzer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/** Daily: compares the fees each marketplace actually charged with the fee tables; e-mails new alerts. */
class CheckFeesCommand extends Command
{
    protected $signature = 'ecso:marketplace:check-fees {--tenant= : Only this tenant id} {--dry-run : Do not send e-mails}';

    protected $description = 'Kiểm tra phí sàn thực tế so với bảng phí, cảnh báo đơn bán lỗ sau phí.';

    public function handle(): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            $alerts = TenantContext::run($tenant, function () {
                $alerts = [];
                foreach (MarketplaceAccount::query()->whereHas('priceRules')->get() as $account) {
                    array_push($alerts, ...app(FeeAnalyzer::class)->check($account));
                }

                return $alerts;
            });
            if ($alerts === []) {
                continue;
            }
            $recipients = TenantUser::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('status', TenantUser::STATUS_ACTIVE)
                ->whereHas('role.permissions', fn ($p) => $p->where('key', 'settings.manage'))
                ->with('user')->get()->pluck('user')->filter();
            $this->line("{$tenant->name}: ".count($alerts).' cảnh báo, '.$recipients->count().' người nhận');
            if (! $this->option('dry-run')) {
                foreach ($recipients as $user) {
                    $user->notify(new FeeAlertNotification($tenant, $alerts));
                }
            }
        }

        return self::SUCCESS;
    }
}
