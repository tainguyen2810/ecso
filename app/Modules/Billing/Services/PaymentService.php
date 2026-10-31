<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records money received for an invoice and activates the plan. Idempotent per (provider, transaction id):
 * the same bank transaction or IPN processed twice is applied once. Runs in the invoice's tenant context,
 * taken from the invoice row (server-side), never from the request.
 */
class PaymentService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array{payment: Payment, duplicate: bool}
     */
    public function record(string $provider, string $transactionId, Invoice $invoice, float $amount, CarbonImmutable $receivedAt, ?int $actorId = null, ?string $note = null, array $raw = []): array
    {
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        return TenantContext::run($tenant, function () use ($provider, $transactionId, $invoice, $amount, $receivedAt, $actorId, $note, $raw) {
            $existing = fn () => Payment::query()->where('provider', $provider)->where('provider_txn_id', $transactionId)->first();
            if ($found = $existing()) {
                return ['payment' => $found, 'duplicate' => true];
            }

            try {
                return DB::transaction(function () use ($provider, $transactionId, $invoice, $amount, $receivedAt, $actorId, $note, $raw) {
                    $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                    // Several partial transfers for one invoice add up; the one that completes it applies the invoice.
                    $paidBefore = (float) Payment::query()->where('invoice_id', $locked->id)->where('status', Payment::UNDERPAID)->sum('amount');
                    $status = match (true) {
                        $locked->status !== Invoice::PENDING => Payment::UNAPPLIED,
                        round($paidBefore + $amount, 2) < round((float) $locked->amount, 2) => Payment::UNDERPAID,
                        default => Payment::APPLIED,
                    };

                    $payment = new Payment([
                        'invoice_id' => $locked->id,
                        'provider' => $provider,
                        'provider_txn_id' => $transactionId,
                        'amount' => $amount,
                        'status' => $status,
                        'received_at' => $receivedAt,
                        'recorded_by' => $actorId,
                        'note' => $note,
                        'raw' => $raw ?: null,
                    ]);
                    $payment->save();

                    if ($status === Payment::APPLIED) {
                        $this->apply($locked);
                    }
                    $this->audit->record('payment.recorded', $payment, ['invoice' => $locked->number, 'amount' => $amount, 'status' => $status, 'provider' => $provider], $locked->tenant_id, $actorId);

                    return ['payment' => $payment, 'duplicate' => false];
                });
            } catch (UniqueConstraintViolationException) {
                // Same transaction recorded concurrently: the other request applied it.
                return ['payment' => $existing() ?? throw new \RuntimeException('Payment vanished after duplicate insert.'), 'duplicate' => true];
            }
        });
    }

    /** Invoice paid in full: the plan becomes active for the paid period. */
    private function apply(Invoice $invoice): void
    {
        $subscription = Subscription::query()->with('plan')->lockForUpdate()->firstOrFail();
        $plan = $invoice->plan()->firstOrFail();

        // A renewal continues the current period; anything else starts now (upgrade credit was already deducted).
        $start = $invoice->kind === Invoice::KIND_RENEWAL && $subscription->current_period_end?->isFuture()
            ? $subscription->current_period_end->copy()
            : Carbon::now();
        $end = $invoice->billing_cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();

        $invoice->forceFill(['status' => Invoice::PAID, 'paid_at' => now(), 'period_start' => $start, 'period_end' => $end])->save();

        if ($invoice->kind === Invoice::KIND_RENEWAL && $subscription->plan_id === $plan->id && $subscription->current_period_end?->isFuture()) {
            $subscription->forceFill(['current_period_end' => $end, 'status' => Subscription::STATUS_ACTIVE, 'past_due_since' => null])->save();
            $this->audit->record('subscription.renewed', $subscription, ['plan' => $plan->key, 'until' => $end->toIso8601String()], $invoice->tenant_id);

            return;
        }

        $this->subscriptions->switchTo($subscription, $plan, $invoice->billing_cycle, Subscription::STATUS_ACTIVE, $start, $end);
    }
}
