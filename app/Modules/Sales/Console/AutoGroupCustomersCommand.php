<?php

namespace App\Modules\Sales\Console;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Puts customers who have no group yet into the group whose buying threshold they reached (Nhóm khách → "Tự xếp
 * khách vào nhóm"). A customer someone already put in a group is never moved. When several groups match, the one
 * with the highest threshold wins.
 */
class AutoGroupCustomersCommand extends Command
{
    protected $signature = 'ecso:customers:auto-group {--tenant= : Only this tenant id} {--dry-run : List, do not change}';

    protected $description = 'Tự xếp khách chưa có nhóm vào nhóm theo ngưỡng chi tiêu/số đơn của nhóm.';

    public function handle(AuditLogger $audit): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($tenants as $tenant) {
            $moved = TenantContext::run($tenant, fn () => $this->groupTenant($audit));
            if ($moved > 0) {
                $this->line("{$tenant->name}: {$moved} khách được xếp nhóm");
            }
        }

        return self::SUCCESS;
    }

    private function groupTenant(AuditLogger $audit): int
    {
        $groups = CustomerGroup::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('auto_min_spent')->orWhereNotNull('auto_min_orders'))
            ->orderByDesc('auto_min_spent')->orderByDesc('auto_min_orders')->get();
        $moved = 0;
        foreach ($groups as $group) {
            $customers = Customer::query()->withStats()->whereNull('customers.customer_group_id')->where('customers.status', 'active')
                ->when($group->auto_min_spent !== null, fn ($q) => $q->having('total_spent', '>=', $group->auto_min_spent))
                ->when($group->auto_min_orders !== null, fn ($q) => $q->having('orders_count', '>=', $group->auto_min_orders))
                ->limit(5000)->get();
            foreach ($customers as $customer) {
                if (! $this->option('dry-run')) {
                    Customer::query()->whereKey($customer->id)->whereNull('customer_group_id')->update(['customer_group_id' => $group->id]);
                    $audit->record('customer.auto_grouped', $customer, ['group' => $group->name, 'spent' => (string) $customer->total_spent, 'orders' => (int) $customer->orders_count]);
                }
                $moved++;
            }
        }

        return $moved;
    }
}
