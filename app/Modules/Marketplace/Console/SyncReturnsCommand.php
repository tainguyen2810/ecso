<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\ReturnSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/** Đợt 6: return and refund requests on the marketplaces come into Hoàn/Trả. */
class SyncReturnsCommand extends Command
{
    protected $signature = 'ecso:marketplace:returns {--tenant= : Only this tenant id}';

    protected $description = 'Lấy yêu cầu trả hàng / hoàn tiền từ sàn về mục Hoàn/Trả.';

    public function handle(ReturnSync $sync): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use ($sync) {
                $accounts = MarketplaceAccount::query()->where('status', MarketplaceAccount::CONNECTED)->get()
                    ->filter(fn (MarketplaceAccount $a) => $sync->supports($a) && ! $a->circuitOpen() && ! $a->setupPending());
                foreach ($accounts as $account) {
                    try {
                        $c = $sync->syncAccount($account);
                        if ($c['read'] > 0) {
                            $this->line("{$account->shop_name}: {$c['read']} yêu cầu, mới {$c['created']}");
                        }
                    } catch (ConnectorException $e) {
                        $this->warn("{$account->shop_name}: {$e->getMessage()}");
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
