<?php

namespace App\Modules\Sales\Console;

use App\Modules\Messaging\Push\PushNotifier;
use App\Modules\Sales\Models\CustomerSubscription;
use App\Modules\Sales\Subscriptions\Reminder;
use App\Modules\Sales\Subscriptions\RenewalService;
use App\Modules\Sales\Subscriptions\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thuê bao, every morning:
 *  1. a renewal order (waiting for confirmation) for each subscription close to its end, when its service says so;
 *  2. the renewal order the customer never confirmed is cancelled once the grace days are over;
 *  3. reminders to the customer on the days of Cấu hình › Thuê bao (Zalo ZNS when a template is chosen), once each;
 *  4. one message to the staff phones: how many subscriptions end within 7 days or have ended.
 */
class SubscriptionDailyCommand extends Command
{
    protected $signature = 'ecso:subscriptions:daily {--tenant= : Only this tenant id}';

    protected $description = 'Thuê bao: tự tạo đơn gia hạn, nhắc khách sắp hết hạn, báo nhân viên.';

    public function handle(RenewalService $renewals, Reminder $reminder): int
    {
        $tenantIds = CustomerSubscription::query()->withoutGlobalScopes()->where('status', CustomerSubscription::ACTIVE)->distinct()->pluck('tenant_id');
        $tenants = Tenant::query()->whereIn('id', $tenantIds)->where('status', Tenant::STATUS_ACTIVE)->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use ($tenant, $renewals, $reminder) {
                $stats = $this->runTenant($renewals, $reminder);
                if (array_sum($stats) > 0) {
                    $this->line("{$tenant->name}: {$stats['orders']} đơn gia hạn, {$stats['dropped']} đơn huỷ, {$stats['reminded']} lần nhắc");
                }
            });
        }

        return self::SUCCESS;
    }

    /** @return array{orders: int, dropped: int, reminded: int} */
    public function runTenant(RenewalService $renewals, Reminder $reminder): array
    {
        $settings = TenantSettings::current();
        $remindDays = array_map('intval', (array) $settings->get('subscriptions.remind_days'));
        $useZalo = (bool) $settings->get('subscriptions.zalo');
        $today = SubscriptionService::today();
        $stats = ['orders' => 0, 'dropped' => 0, 'reminded' => 0];
        $due = 0;

        $subs = CustomerSubscription::query()->with(['product', 'customer', 'variant'])->where('status', CustomerSubscription::ACTIVE)
            ->whereNotNull('ends_on')->where('ends_on', '<=', $today->copy()->addDays(60)->toDateString())->orderBy('id')->get();

        foreach ($subs as $sub) {
            $left = (int) $sub->daysLeft($today);
            $rules = $sub->product->subscriptionRules();
            if ($left <= 7) {
                $due++;
            }
            try {
                if ($left < -$rules['grace_days']) {
                    $stats['dropped'] += $renewals->dropStaleRenewal($sub) ? 1 : 0;

                    continue;
                }
                if ($rules['auto_order_days'] !== null && $left <= $rules['auto_order_days'] && $renewals->draftRenewal($sub) !== null) {
                    $stats['orders']++;
                }
                if ($sub->remind && $rules['remind'] && in_array($left, $remindDays, true)) {
                    $stats['reminded'] += $reminder->send($sub, $left, $useZalo) ? 1 : 0;
                }
            } catch (Throwable $e) {
                Log::warning('subscription.daily_failed', ['subscription' => $sub->id, 'error' => $e->getMessage()]);
            }
        }

        if ($due > 0 && $settings->get('subscriptions.notify_staff')) {
            app(PushNotifier::class)->send('orders.view', 'Thuê bao cần gia hạn', "{$due} thuê bao hết hạn trong 7 ngày tới hoặc đã hết hạn.", '/subscriptions?tab=expiring');
        }

        return $stats;
    }
}
