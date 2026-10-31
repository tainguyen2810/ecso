<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Payments\GatewayRegistry;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Billing\Services\UsageService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function show(TenantContext $context, SubscriptionService $service, UsageService $usage): JsonResponse
    {
        abort_unless($context->can('billing.view'), 403);

        $subscription = $service->current();

        return response()->json(['data' => [
            'plan' => ['key' => $subscription->plan->key, 'name' => $subscription->plan->name],
            'status' => $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'trial_available' => $subscription->trial_used_at === null,
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'past_due_since' => $subscription->past_due_since?->toIso8601String(),
            'grace_until' => $subscription->past_due_since?->copy()->addDays((int) config('billing.grace_days'))->toIso8601String(),
            'pending_plan' => $subscription->pendingPlan ? ['key' => $subscription->pendingPlan->key, 'name' => $subscription->pendingPlan->name] : null,
            'entitlements' => $context->entitlements()->toArray(),
            'usage' => $usage->all(),
            'open_invoice' => ($open = Invoice::query()->with('plan')->where('status', Invoice::PENDING)->latest('id')->first()) ? $this->invoice($open) : null,
            'plans' => Plan::query()->where('is_public', true)->orderBy('sort')->with('features')->get()->map(fn (Plan $plan) => [
                'key' => $plan->key,
                'name' => $plan->name,
                'price_monthly' => (float) $plan->price_monthly,
                'price_yearly' => (float) $plan->price_yearly,
                'trial_days' => $plan->trial_days,
                'features' => $plan->features->mapWithKeys(fn ($f) => [$f->key => [
                    'enabled' => (bool) $f->pivot->getAttribute('enabled'),
                    'limit' => $f->pivot->getAttribute('limit_value'),
                ]]),
            ]),
        ]]);
    }

    public function quote(Request $request, TenantContext $context, SubscriptionService $service): JsonResponse
    {
        abort_unless($context->can('billing.view'), 403);
        [$plan, $cycle] = $this->target($request);

        return response()->json(['data' => [
            ...$service->quote($plan, $cycle),
            'downgrade_blockers' => $service->violations($plan),
        ]]);
    }

    public function change(Request $request, TenantContext $context, SubscriptionService $service): JsonResponse
    {
        abort_unless($context->can('billing.manage'), 403);
        [$plan, $cycle] = $this->target($request);
        $result = $service->change($plan, $cycle, $request->user()->id);

        return response()->json(['data' => [
            'action' => $result['action'],
            'invoice' => isset($result['invoice']) ? $this->invoice($result['invoice']->load('plan')) : null,
        ]], $result['action'] === 'invoice' ? 201 : 200);
    }

    public function trial(Request $request, TenantContext $context, SubscriptionService $service): JsonResponse
    {
        abort_unless($context->can('billing.manage'), 403);
        $plan = Plan::query()->where('key', $request->validate(['plan' => ['required', 'string']])['plan'])->firstOrFail();
        $subscription = $service->startTrial($plan);

        return response()->json(['data' => ['status' => $subscription->status, 'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()]]);
    }

    public function invoices(TenantContext $context): JsonResponse
    {
        abort_unless($context->can('billing.view'), 403);

        return response()->json(['data' => Invoice::query()->with('plan')->latest('id')->limit(50)->get()->map(fn (Invoice $i) => $this->invoice($i))]);
    }

    public function showInvoice(TenantContext $context, Invoice $invoice): JsonResponse
    {
        abort_unless($context->can('billing.view'), 403);

        return response()->json(['data' => $this->invoice($invoice->load('plan'))]);
    }

    public function cancelInvoice(TenantContext $context, Invoice $invoice, SubscriptionService $service): JsonResponse
    {
        abort_unless($context->can('billing.manage'), 403);

        return response()->json(['data' => $this->invoice($service->cancelInvoice($invoice)->load('plan'))]);
    }

    /** @return array{0: Plan, 1: string} */
    private function target(Request $request): array
    {
        $data = $request->validate(['plan' => ['required', 'string'], 'cycle' => ['required', Rule::in(SubscriptionService::CYCLES)]]);

        return [Plan::query()->where('key', $data['plan'])->where('is_public', true)->firstOrFail(), $data['cycle']];
    }

    /** @return array<string, mixed> */
    private function invoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->ulid,
            'number' => $invoice->number,
            'kind' => $invoice->kind,
            'plan' => ['key' => $invoice->plan->key, 'name' => $invoice->plan->name],
            'billing_cycle' => $invoice->billing_cycle,
            'subtotal' => $invoice->subtotal,
            'credit' => $invoice->credit,
            'amount' => $invoice->amount,
            'status' => $invoice->status,
            'transfer_code' => $invoice->transfer_code,
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'payment' => $invoice->status === Invoice::PENDING ? app(GatewayRegistry::class)->default()->instructions($invoice) : null,
        ];
    }
}
