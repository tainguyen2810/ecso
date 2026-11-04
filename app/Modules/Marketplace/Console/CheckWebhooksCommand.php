<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\WooCommerceConnectService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/** Hourly: turns ECSO's webhooks on connected WooCommerce stores back on when Woo disabled them, adds missing ones. */
class CheckWebhooksCommand extends Command
{
    protected $signature = 'ecso:woo:check-webhooks {--account= : ULID of one store}';

    protected $description = 'Kiểm tra webhook trên các website WooCommerce, bật lại webhook bị tắt';

    public function handle(WooCommerceConnectService $service): int
    {
        $accounts = MarketplaceAccount::query()->withoutGlobalScopes()
            ->where('marketplace', WooCommerceConnector::MARKETPLACE)->where('status', MarketplaceAccount::CONNECTED)
            ->when($this->option('account'), fn ($q, $ulid) => $q->where('ulid', $ulid))->get();

        foreach ($accounts as $account) {
            $tenant = Tenant::query()->find($account->tenant_id);
            $health = TenantContext::run($tenant, fn () => $service->checkWebhooks(MarketplaceAccount::query()->findOrFail($account->id)));
            $this->line("{$account->shop_name}: ".($health['ok'] ? 'ok' : 'lỗi').' '.json_encode(array_filter([
                'missing' => $health['missing'], 'reactivated' => $health['reactivated'], 'error' => $health['error'],
            ]), JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
