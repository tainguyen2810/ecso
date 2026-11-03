<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Notifications\LowStockNotification;
use App\Modules\Inventory\Services\LowStockQuery;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Console\Command;

/** Daily e-mail to the people who buy stock, listing SKUs at or below their safety stock. */
class LowStockDigestCommand extends Command
{
    protected $signature = 'ecso:inventory:low-stock-digest {--tenant= : Only this tenant id} {--dry-run : List, do not send}';

    protected $description = 'Gửi email tóm tắt SKU sắp hết hàng (≤ tồn an toàn) cho người có quyền mua hàng.';

    public function handle(LowStockQuery $query): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            $items = $query->forTenant($tenant->id, 200);
            if ($items === []) {
                continue;
            }
            $recipients = TenantUser::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('status', TenantUser::STATUS_ACTIVE)
                ->whereHas('role.permissions', fn ($p) => $p->where('key', 'purchasing.manage'))
                ->with('user')->get()->pluck('user')->filter();

            $this->line("{$tenant->name}: ".count($items).' SKU, '.$recipients->count().' người nhận');
            if (! $this->option('dry-run')) {
                foreach ($recipients as $user) {
                    $user->notify(new LowStockNotification($tenant, $items, count($items)));
                }
            }
        }

        return self::SUCCESS;
    }
}
