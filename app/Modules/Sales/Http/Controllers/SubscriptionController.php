<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerSubscription;
use App\Modules\Sales\Models\CustomerSubscriptionEvent;
use App\Modules\Sales\Models\CustomerSubscriptionPeriod;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Subscriptions\Reminder;
use App\Modules\Sales\Subscriptions\RenewalService;
use App\Modules\Sales\Subscriptions\SubscriptionService;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Thuê bao, gia hạn": the services customers use for a time, how long they have left, renewing, pausing, cancelling.
 * Reading needs orders.view; anything that changes a subscription or makes an order needs orders.create.
 */
class SubscriptionController extends Controller
{
    public const TABS = ['all', 'expiring', 'overdue', 'active', 'pending', 'paused', 'cancelled'];

    public function __construct(private readonly SubscriptionService $subscriptions, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'q' => ['nullable', 'string', 'max:100'],
            'product' => ['nullable', 'string'],
            'customer' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $today = SubscriptionService::today();

        $base = CustomerSubscription::query()
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('customer_subscriptions.code', 'like', $like)->orWhere('label', 'like', $like)
                    ->orWhereIn('customer_id', Customer::query()->select('id')->where(fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('code', 'like', $like)))
                    ->orWhereIn('product_id', Product::query()->select('id')->where('name', 'like', $like)));
            })
            ->when($filters['product'] ?? null, fn (Builder $q, string $ulid) => $q->whereIn('product_id', Product::query()->withTrashed()->select('id')->where('ulid', $ulid)))
            ->when($filters['customer'] ?? null, fn (Builder $q, string $ulid) => $q->whereIn('customer_id', Customer::query()->select('id')->where('ulid', $ulid)));

        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = $this->tab(clone $base, $tab, $today)->count();
        }

        $page = $this->tab(clone $base, $filters['tab'] ?? 'all', $today)
            ->with(['customer', 'product' => fn ($q) => $q->withTrashed(), 'variant', 'assignee:id,ulid,name'])
            ->orderByRaw("case when status = 'active' and ends_on is not null then 0 when status = 'active' then 1 when status = 'paused' then 2 else 3 end")
            ->orderBy('ends_on')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        $pending = CustomerSubscriptionPeriod::query()->with('order:id,ulid,external_order_id,code')
            ->whereIn('customer_subscription_id', $page->getCollection()->pluck('id'))->where('status', CustomerSubscriptionPeriod::PENDING)
            ->get()->keyBy('customer_subscription_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (CustomerSubscription $s) => $this->row($s, $today, $pending->get($s->id)))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => $counts,
                'kpi' => $this->kpi($today),
            ],
        ]);
    }

    public function show(CustomerSubscription $subscription): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $today = SubscriptionService::today();
        $subscription->load(['customer', 'product' => fn ($q) => $q->withTrashed(), 'variant', 'assignee:id,ulid,name']);
        $periods = CustomerSubscriptionPeriod::query()->with(['order:id,ulid,external_order_id,code,status,total,paid_amount,refunded_amount', 'variant'])
            ->where('customer_subscription_id', $subscription->id)->orderByDesc('starts_on')->orderByDesc('id')->get();
        $pending = $periods->firstWhere('status', CustomerSubscriptionPeriod::PENDING);

        return response()->json(['data' => [
            ...$this->row($subscription, $today, $pending),
            'note' => $subscription->note,
            'remind' => $subscription->remind,
            'rules' => $subscription->product->subscriptionRules(),
            'customer_since' => $periods->where('status', CustomerSubscriptionPeriod::ACTIVE)->min('starts_on')?->toDateString(),
            'paid_total' => number_format((float) $periods->where('status', CustomerSubscriptionPeriod::ACTIVE)->sum('amount'), 2, '.', ''),
            'periods' => $periods->map(fn (CustomerSubscriptionPeriod $p) => [
                'id' => $p->id,
                'starts_on' => $p->starts_on->toDateString(),
                'ends_on' => $p->ends_on->toDateString(),
                'months' => $p->months,
                'amount' => $p->amount,
                'status' => $p->status,
                'current' => $p->status === CustomerSubscriptionPeriod::ACTIVE && $p->starts_on->lessThanOrEqualTo($today) && $p->ends_on->greaterThanOrEqualTo($today),
                'variant' => $p->variant ? ['id' => $p->variant->ulid, 'sku' => $p->variant->sku, 'name' => $p->variant->name] : null,
                'order' => $p->order ? ['id' => $p->order->ulid, 'number' => $p->order->displayCode(), 'status' => $p->order->status, 'due' => number_format(max(0, (float) $p->order->total - (float) $p->order->paid_amount - (float) $p->order->refunded_amount), 2, '.', '')] : null,
            ]),
            'events' => CustomerSubscriptionEvent::query()->with('user:id,name')->where('customer_subscription_id', $subscription->id)->latest('id')->limit(100)->get()
                ->map(fn (CustomerSubscriptionEvent $e) => ['id' => $e->id, 'kind' => $e->kind, 'note' => $e->note, 'user' => $e->user?->name, 'at' => $e->created_at?->toIso8601String()]),
            'plans' => $this->plans($subscription->product),
        ]]);
    }

    public function update(Request $request, CustomerSubscription $subscription): JsonResponse
    {
        $this->authorize('create', Order::class);
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:150'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'remind' => ['sometimes', 'boolean'],
            'assigned_to' => ['sometimes', 'nullable', 'string'],
        ]);
        if (array_key_exists('assigned_to', $data)) {
            $data['assigned_to'] = $data['assigned_to'] === null ? null
                : (User::query()->where('ulid', $data['assigned_to'])->whereIn('id', DB::table('tenant_users')->where('tenant_id', $subscription->tenant_id)->where('status', 'active')->select('user_id'))->value('id')
                    ?? throw ValidationException::withMessages(['assigned_to' => 'Nhân viên không thuộc cửa hàng.']));
        }
        $subscription->fill($data)->save();
        $this->subscriptions->log($subscription, 'changed', 'Sửa thông tin thuê bao');

        return $this->show($subscription);
    }

    /** Gia hạn: makes the renewal order (completed and paid by default) and so the new period. */
    public function renew(Request $request, CustomerSubscription $subscription, RenewalService $renewals): JsonResponse
    {
        $this->authorize('create', Order::class);
        $data = $request->validate([
            'variant_id' => ['required', 'string'],
            'start' => ['nullable', 'date_format:Y-m-d'],
            'stage' => ['nullable', Rule::in(['pending', 'confirmed', 'completed'])],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'payments' => ['array', 'max:3'],
            'payments.*.method' => ['required', Rule::in(OrderPayment::METHODS)],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        if (isset($data['unit_price']) && ! TenantSettings::current()->get('pricing.staff_edit_price') && ! $request->user()->can('changePrice', Order::class)) {
            throw ValidationException::withMessages(['unit_price' => 'Bạn không có quyền sửa giá.']);
        }
        $order = $renewals->renew($subscription, $data);

        return (new OrderResource($order->load(['channel', 'customer', 'warehouse'])))->response()->setStatusCode(201);
    }

    public function pause(CustomerSubscription $subscription): JsonResponse
    {
        return $this->move($subscription, [CustomerSubscription::ACTIVE], CustomerSubscription::PAUSED, 'paused', 'Tạm dừng: không nhắc, không tự tạo đơn gia hạn');
    }

    public function resume(CustomerSubscription $subscription): JsonResponse
    {
        return $this->move($subscription, [CustomerSubscription::PAUSED, CustomerSubscription::CANCELLED], CustomerSubscription::ACTIVE, 'resumed', 'Dùng lại thuê bao');
    }

    public function cancel(Request $request, CustomerSubscription $subscription): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        return $this->move($subscription, [CustomerSubscription::ACTIVE, CustomerSubscription::PAUSED], CustomerSubscription::CANCELLED, 'cancelled', 'Huỷ thuê bao'.($reason ? ": {$reason}" : ''), $reason);
    }

    /** Several at once: make the renewal orders (waiting for confirmation) or send the reminder now. */
    public function bulk(Request $request, RenewalService $renewals, Reminder $reminder): JsonResponse
    {
        $this->authorize('create', Order::class);
        $data = $request->validate([
            'action' => ['required', Rule::in(['renewal_orders', 'remind'])],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string'],
        ]);
        $subs = CustomerSubscription::query()->with(['product' => fn ($q) => $q->withTrashed(), 'customer', 'variant'])->whereIn('ulid', $data['ids'])->where('status', CustomerSubscription::ACTIVE)->get();
        $done = 0;
        $skipped = [];
        foreach ($subs as $sub) {
            if ($data['action'] === 'renewal_orders') {
                $sub->ends_on === null || $renewals->draftRenewal($sub) === null ? $skipped[] = $sub->code : $done++;
            } else {
                $sub->ends_on === null ? $skipped[] = $sub->code : ($reminder->send($sub, (int) $sub->daysLeft(), (bool) TenantSettings::current()->get('subscriptions.zalo'), true) ? $done++ : null);
            }
        }

        return response()->json(['data' => ['done' => $done, 'skipped' => $skipped]]);
    }

    /** Plans (cycles) of the service with their price, for the renew dialog. */
    public static function plans(Product $product): array
    {
        return ProductVariant::query()->where('product_id', $product->id)->where('status', 'active')->orderBy('period_months')->get()
            ->map(fn (ProductVariant $v) => ['id' => $v->ulid, 'sku' => $v->sku, 'name' => $v->name, 'period_months' => $v->period_months, 'ref_price' => $v->ref_price, 'wholesale_price' => $v->wholesale_price])->all();
    }

    private function move(CustomerSubscription $subscription, array $from, string $to, string $kind, string $note, ?string $reason = null): JsonResponse
    {
        $this->authorize('create', Order::class);
        DB::transaction(function () use ($subscription, $from, $to, $kind, $note, $reason) {
            $sub = CustomerSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if (! in_array($sub->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'Thuê bao đang ở trạng thái không làm được việc này.']);
            }
            $sub->forceFill([
                'status' => $to,
                'paused_at' => $to === CustomerSubscription::PAUSED ? now() : null,
                'cancelled_at' => $to === CustomerSubscription::CANCELLED ? now() : null,
                'cancel_reason' => $to === CustomerSubscription::CANCELLED ? $reason : null,
            ])->save();
            $this->subscriptions->log($sub, $kind, $note);
            $this->audit->record("subscription.{$kind}", $sub, ['code' => $sub->code]);
        });

        return $this->show($subscription->refresh());
    }

    /**
     * @param  Builder<CustomerSubscription>  $q
     * @return Builder<CustomerSubscription>
     */
    private function tab(Builder $q, string $tab, Carbon $today): Builder
    {
        $day = $today->toDateString();

        return match ($tab) {
            'expiring' => $q->where('customer_subscriptions.status', CustomerSubscription::ACTIVE)->whereBetween('customer_subscriptions.ends_on', [$day, $today->copy()->addDays(30)->toDateString()]),
            'overdue' => $q->where('customer_subscriptions.status', CustomerSubscription::ACTIVE)->where('customer_subscriptions.ends_on', '<', $day),
            'active' => $q->where('customer_subscriptions.status', CustomerSubscription::ACTIVE)->where('customer_subscriptions.ends_on', '>=', $day),
            'pending' => $q->where('customer_subscriptions.status', CustomerSubscription::ACTIVE)->whereNull('customer_subscriptions.ends_on'),
            'paused' => $q->where('customer_subscriptions.status', CustomerSubscription::PAUSED),
            'cancelled' => $q->where('customer_subscriptions.status', CustomerSubscription::CANCELLED),
            default => $q,
        };
    }

    /** @return array<string, mixed> */
    private function row(CustomerSubscription $s, Carbon $today, ?CustomerSubscriptionPeriod $pending): array
    {
        return [
            'id' => $s->ulid,
            'code' => $s->code,
            'customer' => $s->customer ? ['id' => $s->customer->ulid, 'code' => $s->customer->code, 'name' => $s->customer->name, 'phone' => $s->customer->phone] : null,
            'product' => ['id' => $s->product->ulid, 'name' => $s->product->name],
            'variant' => $s->variant ? ['id' => $s->variant->ulid, 'sku' => $s->variant->sku, 'name' => $s->variant->name, 'period_months' => $s->variant->period_months, 'ref_price' => $s->variant->ref_price] : null,
            'label' => $s->label,
            'status' => $s->status,
            'state' => $s->state($today),
            'starts_on' => $s->starts_on?->toDateString(),
            'ends_on' => $s->ends_on?->toDateString(),
            'days_left' => $s->daysLeft($today),
            'grace_days' => $s->product->subscriptionRules()['grace_days'],
            'pending_order' => $pending?->order ? ['id' => $pending->order->ulid, 'number' => $pending->order->displayCode()] : null,
            'reminders' => count(array_filter($s->reminded ?? [], fn ($k) => $s->ends_on !== null && str_starts_with((string) $k, $s->ends_on->toDateString()))),
            'assignee' => $s->assignee ? ['id' => $s->assignee->ulid, 'name' => $s->assignee->name] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function kpi(Carbon $today): array
    {
        $day = $today->toDateString();
        $in30 = $today->copy()->addDays(30)->toDateString();
        $active = CustomerSubscription::query()->where('status', CustomerSubscription::ACTIVE)->where('ends_on', '>=', $day);
        $expiring = CustomerSubscription::query()->where('customer_subscriptions.status', CustomerSubscription::ACTIVE)->whereBetween('customer_subscriptions.ends_on', [$day, $in30]);
        $expiringValue = (clone $expiring)->join('product_variants', 'product_variants.id', '=', 'customer_subscriptions.product_variant_id')->sum('product_variants.ref_price');
        $overdue = CustomerSubscription::query()->with('product')->where('status', CustomerSubscription::ACTIVE)->where('ends_on', '<', $day)->get(['id', 'product_id', 'ends_on', 'status']);
        // Doanh thu thuê bao / tháng: what the periods running on a day are worth per month.
        $monthly = fn (string $d) => (float) CustomerSubscriptionPeriod::query()->where('status', CustomerSubscriptionPeriod::ACTIVE)
            ->where('starts_on', '<=', $d)->where('ends_on', '>=', $d)->sum(DB::raw('amount / months'));

        return [
            'active' => (clone $active)->count(),
            'customers' => (clone $active)->distinct()->count('customer_id'),
            'expiring' => (clone $expiring)->count(),
            'expiring_value' => number_format((float) $expiringValue, 2, '.', ''),
            'overdue' => $overdue->count(),
            'in_grace' => $overdue->filter(fn (CustomerSubscription $s) => $s->state($today) === 'overdue')->count(),
            'monthly' => number_format($monthly($day), 2, '.', ''),
            'monthly_prev' => number_format($monthly($today->copy()->subMonthNoOverflow()->toDateString()), 2, '.', ''),
        ];
    }
}
