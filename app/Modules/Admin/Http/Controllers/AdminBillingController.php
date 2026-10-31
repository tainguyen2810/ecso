<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Payments\BankTransferGateway;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform billing for super admins: subscription invoices and payments of the SaaS itself (not tenants'
 * business data). Payments by bank transfer are confirmed here against the bank statement.
 */
class AdminBillingController extends Controller
{
    public function summary(): JsonResponse
    {
        $subs = Subscription::withoutGlobalScopes()->with('plan')->get();
        $paying = $subs->filter(fn (Subscription $s) => in_array($s->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)
            && ! $s->plan->isFree() && $s->current_period_end?->isFuture());

        $mrr = $paying->sum(fn (Subscription $s) => $s->plan->monthlyValue($s->billing_cycle));
        $collected30 = (float) Payment::withoutGlobalScopes()->where('status', Payment::APPLIED)->where('received_at', '>=', now()->subDays(30))->sum('amount');

        return response()->json(['data' => [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'paying_tenants' => $paying->count(),
            'trialing' => $subs->where('status', Subscription::STATUS_TRIALING)->count(),
            'past_due' => $subs->where('status', Subscription::STATUS_PAST_DUE)->count(),
            'collected_30d' => $collected30,
            'pending_invoices' => Invoice::withoutGlobalScopes()->where('status', Invoice::PENDING)->count(),
            'pending_amount' => (float) Invoice::withoutGlobalScopes()->where('status', Invoice::PENDING)->sum('amount'),
            'by_plan' => Plan::query()->orderBy('sort')->get()->map(fn (Plan $p) => [
                'key' => $p->key,
                'name' => $p->name,
                'tenants' => $subs->where('plan_id', $p->id)->count(),
                'mrr' => round($paying->where('plan_id', $p->id)->sum(fn (Subscription $s) => $p->monthlyValue($s->billing_cycle)), 2),
            ]),
            'bank_configured' => app(BankTransferGateway::class)->instructions(new Invoice(['amount' => 0, 'transfer_code' => '']))['configured'],
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in([Invoice::PENDING, Invoice::PAID, Invoice::CANCELLED])], 'q' => ['nullable', 'string', 'max:50']]);
        $page = Invoice::withoutGlobalScopes()->with(['plan', 'tenant:id,ulid,name', 'payments' => fn ($q) => $q->withoutGlobalScopes()])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")->orWhere('transfer_code', $term)
                ->orWhereIn('tenant_id', Tenant::query()->where('name', 'like', "%{$term}%")->select('id'))))
            ->orderByRaw("FIELD(status, 'pending', 'paid', 'cancelled')")->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $i) => $this->row($i)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Records a bank transfer seen on the statement. The bank reference makes this idempotent. */
    public function confirmPayment(Request $request, string $invoice, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $model = Invoice::withoutGlobalScopes()->where('ulid', $invoice)->firstOrFail();

        $result = $payments->record(BankTransferGateway::KEY, trim($data['reference']), $model, (float) $data['amount'],
            CarbonImmutable::parse($data['received_at']), $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => [
            'duplicate' => $result['duplicate'],
            'payment_status' => $result['payment']->status,
            'invoice' => $this->row($model->fresh(['plan', 'tenant']) ?? $model),
        ]], $result['duplicate'] ? 200 : 201);
    }

    public function cancelInvoice(Request $request, string $invoice, AuditLogger $audit): JsonResponse
    {
        $model = Invoice::withoutGlobalScopes()->where('ulid', $invoice)->firstOrFail();
        abort_unless($model->status === Invoice::PENDING, 422, 'Chỉ hủy được hóa đơn chưa thanh toán.');
        DB::transaction(function () use ($model, $audit, $request) {
            $model->forceFill(['status' => Invoice::CANCELLED, 'cancelled_at' => now(), 'note' => $request->input('note')])->save();
            $audit->record('invoice.cancelled_by_admin', $model, ['number' => $model->number], $model->tenant_id, $request->user()->id);
        });

        return response()->json(['data' => $this->row($model->load(['plan', 'tenant']))]);
    }

    /** @return array<string, mixed> */
    private function row(Invoice $i): array
    {
        return [
            'id' => $i->ulid,
            'number' => $i->number,
            'tenant' => $i->tenant ? ['id' => $i->tenant->ulid, 'name' => $i->tenant->name] : null,
            'plan' => $i->plan->name,
            'billing_cycle' => $i->billing_cycle,
            'kind' => $i->kind,
            'amount' => $i->amount,
            'credit' => $i->credit,
            'status' => $i->status,
            'transfer_code' => $i->transfer_code,
            'due_at' => $i->due_at?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
            'payments' => $i->relationLoaded('payments') ? $i->payments->map(fn (Payment $p) => [
                'reference' => $p->provider_txn_id, 'amount' => $p->amount, 'status' => $p->status, 'received_at' => $p->received_at->toIso8601String(),
            ]) : [],
        ];
    }
}
