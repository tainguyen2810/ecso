<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Plan changes (ROADMAP Phase 6). Code never checks plan keys for features: limits come from entitlements.
 *
 * - Upgrade / first paid plan: an invoice is issued; the plan changes only when the invoice is paid.
 *   The unused part of the current paid period is credited.
 * - Downgrade to a cheaper plan: scheduled for the end of the paid period, refused while usage exceeds the
 *   new plan's limits (the tenant must first remove users/shops/warehouses).
 * - Trial: once per tenant, for plans that offer one.
 */
class SubscriptionService
{
    public const CYCLES = ['monthly', 'yearly'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageService $usage,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): Subscription
    {
        return Subscription::query()->with(['plan', 'pendingPlan'])->firstOrFail();
    }

    /** @return array{subtotal: float, credit: float, amount: float, kind: string} */
    public function quote(Plan $plan, string $cycle): array
    {
        $subscription = $this->current();
        $subtotal = $plan->price($cycle);
        $credit = 0.0;
        $kind = Invoice::KIND_SUBSCRIBE;

        if ($this->isPaidAndRunning($subscription)) {
            $sameTerms = $subscription->plan_id === $plan->id && $subscription->billing_cycle === $cycle;
            $kind = $sameTerms ? Invoice::KIND_RENEWAL : Invoice::KIND_UPGRADE;
            if (! $sameTerms) {
                $credit = min($subtotal, $this->unusedValue($subscription));
            }
        }

        $amount = $this->round(max(0, $subtotal - $credit));

        return ['subtotal' => $subtotal, 'credit' => round($subtotal - $amount, 2), 'amount' => $amount, 'kind' => $kind];
    }

    /**
     * @return array{action: 'invoice', invoice: Invoice}|array{action: 'scheduled', subscription: Subscription}|array{action: 'switched', subscription: Subscription}
     */
    public function change(Plan $plan, string $cycle, ?int $userId): array
    {
        return DB::transaction(function () use ($plan, $cycle, $userId) {
            $this->lockTenant();
            $subscription = $this->current();
            $this->assertPublic($plan);

            if ($plan->isFree() || $plan->price($cycle) < $this->currentPrice($subscription)) {
                return $this->downgrade($subscription, $plan, $cycle);
            }

            $quote = $this->quote($plan, $cycle);
            if ($quote['kind'] === Invoice::KIND_RENEWAL && $subscription->current_period_end?->gt(now()->addDays((int) config('billing.renewal_notice_days')))) {
                throw ValidationException::withMessages(['plan' => 'Bạn đang dùng gói này. Hóa đơn gia hạn sẽ được tạo trước khi hết kỳ.']);
            }

            // One open invoice at a time: a new choice replaces the previous unpaid one.
            Invoice::query()->where('status', Invoice::PENDING)->update(['status' => Invoice::CANCELLED, 'cancelled_at' => now(), 'note' => 'Thay bằng hóa đơn mới']);

            $invoice = $this->issue($plan, $cycle, $quote, $userId);
            $subscription->forceFill(['pending_plan_id' => null, 'pending_billing_cycle' => null])->save();

            return ['action' => 'invoice', 'invoice' => $invoice];
        });
    }

    public function startTrial(Plan $plan): Subscription
    {
        return DB::transaction(function () use ($plan) {
            $this->lockTenant();
            $subscription = $this->current();
            $this->assertPublic($plan);

            if ($plan->trial_days <= 0) {
                throw ValidationException::withMessages(['plan' => 'Gói này không có dùng thử.']);
            }
            if ($subscription->trial_used_at !== null) {
                throw ValidationException::withMessages(['plan' => 'Doanh nghiệp đã dùng thử một lần.']);
            }
            if ($this->isPaidAndRunning($subscription)) {
                throw ValidationException::withMessages(['plan' => 'Đang dùng gói trả phí, không cần dùng thử.']);
            }

            $from = $subscription->plan->key;
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays($plan->trial_days),
                'trial_used_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => null,
            ])->save();
            $this->audit->record('subscription.trial_started', $subscription, ['from' => $from, 'plan' => $plan->key, 'days' => $plan->trial_days]);

            return $subscription->load('plan');
        });
    }

    public function cancelInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status !== Invoice::PENDING) {
            throw ValidationException::withMessages(['invoice' => 'Chỉ hủy được hóa đơn chưa thanh toán.']);
        }
        $invoice->forceFill(['status' => Invoice::CANCELLED, 'cancelled_at' => now()])->save();
        $this->audit->record('invoice.cancelled', $invoice, ['number' => $invoice->number]);

        return $invoice;
    }

    /**
     * Issues an invoice. Also used by the renewal sweep (outside a request).
     *
     * @param  array{subtotal: float, credit: float, amount: float, kind: string}  $quote
     */
    public function issue(Plan $plan, string $cycle, array $quote, ?int $userId): Invoice
    {
        $invoice = new Invoice([
            'number' => 'TMP-'.Str::random(16),
            'kind' => $quote['kind'],
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'subtotal' => $quote['subtotal'],
            'credit' => $quote['credit'],
            'amount' => $quote['amount'],
            'status' => Invoice::PENDING,
            'transfer_code' => $this->transferCode(),
            'due_at' => now()->addDays((int) config('billing.invoice_due_days')),
            'created_by' => $userId,
        ]);
        $invoice->save();
        // Number from the primary key: unique without a lock on a sequence row.
        $invoice->forceFill(['number' => 'INV'.now()->format('ym').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)])->save();
        $this->audit->record('invoice.issued', $invoice, ['number' => $invoice->number, 'plan' => $plan->key, 'amount' => $quote['amount']]);

        return $invoice;
    }

    /**
     * Usage that does not fit the plan's limits, for a downgrade check.
     *
     * @return array<string, array{used: int, limit: int}>
     */
    public function violations(Plan $plan): array
    {
        $limits = $plan->features()->get()->mapWithKeys(fn ($f) => [$f->key => $f->pivot->getAttribute('enabled') ? $f->pivot->getAttribute('limit_value') : 0]);
        $out = [];
        foreach (UsageService::HARD_LIMITS as $feature) {
            // Missing feature = not included (0); null = unlimited.
            $limit = $limits->has($feature) ? $limits->get($feature) : 0;
            $used = $this->usage->current($feature);
            if (is_numeric($limit) && $used > (int) $limit) {
                $out[$feature] = ['used' => $used, 'limit' => (int) $limit];
            }
        }

        return $out;
    }

    private function downgrade(Subscription $subscription, Plan $plan, string $cycle): array
    {
        if ($subscription->plan_id === $plan->id) {
            throw ValidationException::withMessages(['plan' => 'Bạn đang dùng gói này.']);
        }
        if ($violations = $this->violations($plan)) {
            $labels = ['limit.users' => 'người dùng', 'limit.shops' => 'shop', 'limit.warehouses' => 'kho'];
            $parts = array_map(fn ($k, $v) => "{$v['used']}/{$v['limit']} ".($labels[$k] ?? $k), array_keys($violations), $violations);
            throw ValidationException::withMessages(['plan' => 'Không thể chuyển gói: đang dùng vượt giới hạn của gói mới ('.implode(', ', $parts).'). Hãy giảm trước khi chuyển.']);
        }

        if ($this->isPaidAndRunning($subscription)) {
            // Paid time is not refunded: the change happens when the period ends.
            $subscription->forceFill(['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle])->save();
            $this->audit->record('subscription.downgrade_scheduled', $subscription, ['plan' => $plan->key, 'at' => $subscription->current_period_end?->toIso8601String()]);

            return ['action' => 'scheduled', 'subscription' => $subscription->load(['plan', 'pendingPlan'])];
        }

        // Free, trial or past due: switch now (a paid target still needs an invoice, handled by change()).
        $this->switchTo($subscription, $plan, $cycle, Subscription::STATUS_ACTIVE, now(), null);

        return ['action' => 'switched', 'subscription' => $subscription->load('plan')];
    }

    public function switchTo(Subscription $subscription, Plan $plan, string $cycle, string $status, ?Carbon $start, ?Carbon $end): void
    {
        $from = $subscription->plan()->value('key');
        $subscription->forceFill([
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'status' => $status,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'trial_ends_at' => null,
            'past_due_since' => null,
            'pending_plan_id' => null,
            'pending_billing_cycle' => null,
        ])->save();
        $this->audit->record('subscription.plan_changed', $subscription, ['from' => $from, 'to' => $plan->key, 'cycle' => $cycle, 'status' => $status], $subscription->tenant_id);
    }

    public function isPaidAndRunning(Subscription $subscription): bool
    {
        return in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)
            && ! $subscription->plan->isFree()
            && $subscription->current_period_end !== null;
    }

    private function currentPrice(Subscription $subscription): float
    {
        return $this->isPaidAndRunning($subscription) ? $subscription->plan->price($subscription->billing_cycle) : 0.0;
    }

    /** Value of the days left in the current paid period. */
    private function unusedValue(Subscription $subscription): float
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;
        if (! $start || ! $end || $end->lte(now())) {
            return 0.0;
        }
        $total = max(1, $start->diffInSeconds($end));
        $left = now()->diffInSeconds($end);

        return round($subscription->plan->price($subscription->billing_cycle) * $left / $total, 2);
    }

    private function round(float $amount): float
    {
        $step = max(1, (int) config('billing.rounding'));

        return (float) (ceil($amount / $step) * $step);
    }

    private function assertPublic(Plan $plan): void
    {
        if (! $plan->is_public) {
            throw ValidationException::withMessages(['plan' => 'Gói không tồn tại.']);
        }
    }

    private function lockTenant(): void
    {
        DB::table('tenants')->where('id', $this->context->id())->lockForUpdate()->value('id');
    }

    private function transferCode(): string
    {
        do {
            $code = 'ECSO'.Str::upper(Str::random(6));
        } while (Invoice::withoutGlobalScopes()->where('transfer_code', $code)->exists());

        return $code;
    }
}
