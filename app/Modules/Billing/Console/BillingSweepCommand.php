<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly subscription housekeeping, per tenant in its own context:
 *  1. ended trials move to Free;
 *  2. renewal invoices are issued shortly before a paid period ends;
 *  3. at the end of a period a scheduled downgrade is applied;
 *  4. an unpaid ended period becomes past due, and after the grace period the tenant moves to Free.
 * Data is never deleted; only entitlements change. Re-running is harmless.
 */
class BillingSweepCommand extends Command
{
    protected $signature = 'ecso:billing:sweep';

    protected $description = 'Hết hạn dùng thử, phát hành hóa đơn gia hạn, chuyển gói khi hết kỳ';

    public function handle(SubscriptionService $service): int
    {
        $free = Plan::query()->where('key', Plan::FREE)->firstOrFail();
        $counts = ['trial_ended' => 0, 'renewal_invoices' => 0, 'downgraded' => 0, 'past_due' => 0, 'to_free' => 0];

        $tenantIds = Subscription::withoutGlobalScopes()->where(function ($q) {
            $q->where('status', '!=', Subscription::STATUS_ACTIVE)->orWhereNotNull('current_period_end');
        })->pluck('tenant_id');

        foreach (Tenant::query()->whereIn('id', $tenantIds)->get() as $tenant) {
            TenantContext::run($tenant, function () use ($service, $free, &$counts) {
                DB::transaction(function () use ($service, $free, &$counts) {
                    $sub = Subscription::query()->with(['plan', 'pendingPlan'])->lockForUpdate()->firstOrFail();

                    if ($sub->status === Subscription::STATUS_TRIALING) {
                        if ($sub->trial_ends_at?->isPast()) {
                            $service->switchTo($sub, $free, 'monthly', Subscription::STATUS_ACTIVE, now(), null);
                            $counts['trial_ended']++;
                        }

                        return;
                    }

                    $end = $sub->current_period_end;
                    if ($end === null || $sub->plan->isFree()) {
                        return;
                    }

                    if ($end->isFuture()) {
                        $noticeAt = $end->copy()->subDays((int) config('billing.renewal_notice_days'));
                        $hasOpen = Invoice::query()->where('status', Invoice::PENDING)->exists();
                        if (now()->gte($noticeAt) && ! $hasOpen && $sub->pending_plan_id === null) {
                            $service->issue($sub->plan, $sub->billing_cycle, [
                                'subtotal' => $sub->plan->price($sub->billing_cycle), 'credit' => 0.0,
                                'amount' => $sub->plan->price($sub->billing_cycle), 'kind' => Invoice::KIND_RENEWAL,
                            ], null);
                            $counts['renewal_invoices']++;
                        }

                        return;
                    }

                    // The paid period is over.
                    if ($sub->pendingPlan) {
                        $target = $sub->pendingPlan;
                        $cycle = $sub->pending_billing_cycle ?? 'monthly';
                        if ($target->isFree()) {
                            $service->switchTo($sub, $target, $cycle, Subscription::STATUS_ACTIVE, now(), null);
                        } else {
                            $service->switchTo($sub, $target, $cycle, Subscription::STATUS_PAST_DUE, $end, $end);
                            $sub->forceFill(['past_due_since' => now()])->save();
                            Invoice::query()->where('status', Invoice::PENDING)->update(['status' => Invoice::CANCELLED, 'cancelled_at' => now()]);
                            $service->issue($target, $cycle, ['subtotal' => $target->price($cycle), 'credit' => 0.0, 'amount' => $target->price($cycle), 'kind' => Invoice::KIND_SUBSCRIBE], null);
                        }
                        $counts['downgraded']++;

                        return;
                    }

                    if ($sub->status !== Subscription::STATUS_PAST_DUE) {
                        $sub->forceFill(['status' => Subscription::STATUS_PAST_DUE, 'past_due_since' => now()])->save();
                        $counts['past_due']++;

                        return;
                    }

                    if ($sub->past_due_since?->copy()->addDays((int) config('billing.grace_days'))->isPast()) {
                        $service->switchTo($sub, $free, 'monthly', Subscription::STATUS_ACTIVE, now(), null);
                        $counts['to_free']++;
                    }
                });
            });
        }

        $this->info(json_encode($counts));

        return self::SUCCESS;
    }
}
