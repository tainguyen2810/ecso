<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Http\Resources\CustomerAddressResource;
use App\Modules\Sales\Http\Resources\CustomerNoteResource;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerDebt;
use App\Modules\Sales\Services\CustomerLedger;
use App\Modules\Sales\Services\CustomerService;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);
        $filters = $request->validate(self::filterRules());

        $query = self::filtered(Customer::query()->withStats()->with(['customerGroup', 'assignee']), $filters);
        self::sorted($query, $filters);

        return CustomerResource::collection($query->paginate((int) ($filters['per_page'] ?? 30)));
    }

    /**
     * The list filters; the export takes the same ones so it can save what the list shows.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(Customer::STATUSES)],
            'debt' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'gender' => ['nullable', Rule::in(Customer::GENDERS)],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'assigned' => ['nullable', 'string', 'max:40'],
            'tag' => ['nullable', 'string', 'max:40'],
            'spent_min' => ['nullable', 'numeric', 'min:0'],
            'spent_max' => ['nullable', 'numeric', 'min:0'],
            'orders_min' => ['nullable', 'integer', 'min:0'],
            'last_order_from' => ['nullable', 'date'],
            'last_order_to' => ['nullable', 'date'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(Customer::SOURCES)],
            'segment' => ['nullable', Rule::in(array_keys(Customer::SEGMENTS))],
            'type' => ['nullable', Rule::in(Customer::TYPES)],
            'sort' => ['nullable', Rule::in(['newest', 'name', 'spent', 'debt', 'recent', 'orders', 'overdue'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /**
     * Tags in use, for the filter and the form's suggestions.
     */
    public function tags(): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);
        $tags = Customer::query()->whereNotNull('tags')->pluck('tags')->flatten()->unique(fn ($t) => mb_strtolower($t))->sort()->values();

        return response()->json(['data' => $tags]);
    }

    /**
     * Active members of the shop, who can be put in charge of a customer.
     */
    public function staff(): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);
        $members = TenantUser::query()->with('user:id,ulid,name')->where('status', TenantUser::STATUS_ACTIVE)->get();

        return response()->json(['data' => $members->filter(fn (TenantUser $m) => $m->user !== null)->map(fn ($m) => ['id' => $m->user->ulid, 'name' => $m->user->name])->sortBy('name')->values()]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $stats = Customer::query()->withStats()->with(['customerGroup', 'assignee'])->whereKey($customer->id)->firstOrFail();

        return (new CustomerResource($stats))->additional(['data' => [
            'summary' => $this->summary($customer),
            'orders' => OrderResource::collection($customer->orders()->with('channel')->orderByDesc('placed_at')->limit(50)->get()),
            'payments' => PaymentResource::collection($customer->payments()->with(['order', 'creator'])->orderByDesc('paid_at')->limit(20)->get()),
            'addresses' => CustomerAddressResource::collection($customer->addresses()->orderBy('id')->get()),
            'notes' => CustomerNoteResource::collection($customer->notes()->with('author:id,name')->latest('id')->limit(100)->get()),
        ]])->response();
    }

    /**
     * Buying habits from real orders (cancelled and returned ones left out, as in the sales reports).
     *
     * @return array<string, mixed>
     */
    private function summary(Customer $customer): array
    {
        $orders = $customer->orders()->whereNotIn('status', [OrderStatus::CANCELLED, OrderStatus::RETURNED]);
        $row = (clone $orders)->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total, MIN(placed_at) as first_at, MAX(placed_at) as last_at')->first();
        $n = (int) ($row->n ?? 0);
        $first = $row?->first_at ? Carbon::parse($row->first_at) : null;
        $last = $row?->last_at ? Carbon::parse($row->last_at) : null;
        $returned = (string) (OrderReturn::query()->whereIn('order_id', $customer->orders()->select('id'))
            ->whereIn('status', CustomerDebt::COUNTED_RETURNS)->selectRaw('COALESCE(SUM('.CustomerDebt::returnValueSql('order_returns').'), 0) as v')->value('v') ?? '0');

        return [
            'first_order_at' => $first?->toIso8601String(),
            'average_order' => $n ? bcdiv((string) $row->total, (string) $n, 2) : null,
            // Average days between two purchases (needs at least two orders).
            'purchase_interval_days' => $n > 1 && $first && $last ? round($first->diffInDays($last) / ($n - 1), 1) : null,
            'days_since_last_order' => $last ? (int) $last->startOfDay()->diffInDays(now()->startOfDay()) : null,
            'returned_value' => bcadd($returned, '0', 2),
            'terms_days' => $customer->payment_terms_days ?? $customer->customerGroup?->payment_terms_days,
            'credit_limit' => $customer->credit_limit !== null ? (string) $customer->credit_limit : ($customer->customerGroup?->credit_limit !== null ? (string) $customer->customerGroup->credit_limit : null),
            // What they buy most, by quantity, from the same orders.
            'top_products' => DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
                ->where('o.customer_id', $customer->id)->whereNotIn('o.status', [OrderStatus::CANCELLED, OrderStatus::RETURNED])
                ->groupBy('i.name', 'i.variant_name', 'i.seller_sku')
                ->selectRaw('i.name, i.variant_name, i.seller_sku as sku, SUM(i.quantity) as quantity, SUM(i.unit_price * i.quantity - i.discount) as amount, COUNT(DISTINCT o.id) as orders')
                ->orderByDesc('quantity')->limit(5)->get()
                ->map(fn ($r) => ['name' => $r->name, 'variant_name' => $r->variant_name, 'sku' => $r->sku, 'quantity' => (int) $r->quantity, 'amount' => bcadd((string) $r->amount, '0', 2), 'orders' => (int) $r->orders])->all(),
        ];
    }

    /**
     * Debt book: each counted order adds its total, each payment on it subtracts, with the running balance.
     */
    public function ledger(Customer $customer, CustomerLedger $ledger): JsonResponse
    {
        $this->authorize('view', $customer);

        return response()->json(['data' => $ledger->entries($customer)]);
    }

    public function store(Request $request, CustomerService $customers): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $customer = $customers->create($this->validated($request));

        return (new CustomerResource($customer->load(['customerGroup', 'assignee'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Customer $customer, CustomerService $customers): CustomerResource
    {
        $this->authorize('update', $customer);

        return new CustomerResource($customers->update($customer, $this->validated($request))->load(['customerGroup', 'assignee']));
    }

    public function destroy(Customer $customer, CustomerService $customers): JsonResponse
    {
        $this->authorize('delete', $customer);
        $customers->delete($customer);

        return response()->json(null, 204);
    }

    public function bulk(Request $request, CustomerService $customers): JsonResponse
    {
        $this->authorize($request->input('action') === 'delete' ? 'delete' : 'update', Customer::class);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
            'action' => ['required', Rule::in(CustomerService::BULK_ACTIONS)],
            'value' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json($customers->bulk(Customer::query()->whereIn('ulid', $data['ids'])->get(), $data['action'], $data['value'] ?? null));
    }

    /**
     * Customer segments (Customer::SEGMENTS), computed from real orders each time.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public static function segment(Builder $query, string $segment): Builder
    {
        $counted = "'".OrderStatus::CANCELLED."','".OrderStatus::RETURNED."'";

        return match ($segment) {
            // Bought before, nothing for LAPSED_DAYS.
            'lapsed' => $query->having('orders_count', '>', 0)->having('last_order_at', '<', now()->subDays(Customer::LAPSED_DAYS)),
            // FREQUENT_ORDERS orders or more in the last FREQUENT_DAYS.
            'frequent' => $query->whereRaw('(SELECT COUNT(*) FROM orders fo WHERE fo.customer_id = customers.id AND fo.status NOT IN ('.$counted.') AND fo.placed_at >= ?) >= ?', [now()->subDays(Customer::FREQUENT_DAYS), Customer::FREQUENT_ORDERS]),
            // The top tenth of buyers by spending.
            'high_value' => $query->having('total_spent', '>=', self::topSpendThreshold())->having('total_spent', '>', 0),
            'new' => $query->where('customers.created_at', '>=', now()->subDays(Customer::NEW_DAYS)),
            'follow_up' => $query->whereExists(fn ($t) => $t->from('customer_tasks')->whereColumn('customer_tasks.customer_id', 'customers.id')->whereNull('customer_tasks.done_at')->where('customer_tasks.due_at', '<=', now()->endOfDay())),
            default => $query,
        };
    }

    /** Spending of the buyer at the 90th percentile (the lowest spend that is still in the top tenth). */
    private static function topSpendThreshold(): float
    {
        // Same figure as withStats()'s total_spent: orders of the customer, cancelled and returned ones left out.
        $spends = Order::query()->whereNotNull('customer_id')->whereNotIn('status', [OrderStatus::CANCELLED, OrderStatus::RETURNED])
            ->groupBy('customer_id')->havingRaw('SUM(total) > 0')->orderByRaw('SUM(total) DESC')->toBase()->pluck(DB::raw('SUM(total)'));
        if ($spends->isEmpty()) {
            return INF;
        }

        return (float) $spends[max(0, (int) ceil($spends->count() * 0.1) - 1)];
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    public static function sorted(Builder $query, array $filters): Builder
    {
        $column = ['name' => 'customers.name', 'spent' => 'total_spent', 'debt' => 'debt', 'overdue' => 'overdue', 'recent' => 'last_order_at', 'orders' => 'orders_count'][$filters['sort'] ?? ''] ?? 'customers.id';
        // Names read A → Z by default, figures largest first.
        $dir = $filters['dir'] ?? ($column === 'customers.name' ? 'asc' : 'desc');

        return $query->orderBy($column, $dir)->orderBy('customers.id', $dir);
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    public static function filtered(Builder $query, array $filters): Builder
    {
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $digits = PhoneNumber::normalize($q);
            $query->where(function ($w) use ($q, $digits) {
                $w->where('customers.name', 'like', "%{$q}%")->orWhere('customers.code', 'like', "%{$q}%")->orWhere('customers.email', 'like', "%{$q}%");
                if ($digits !== null && strlen($digits) >= 9) {
                    $w->orWhere('customers.phone_hash', PhoneNumber::hash($digits));
                } elseif ($digits !== null && strlen($digits) === 4) {
                    $w->orWhere('customers.phone_last4', $digits);
                }
            });
        }
        if ($group = $filters['group'] ?? null) {
            $group === 'none'
                ? $query->whereNull('customers.customer_group_id')
                : $query->whereHas('customerGroup', fn ($g) => $g->where('ulid', $group));
        }
        if ($assigned = $filters['assigned'] ?? null) {
            $assigned === 'none'
                ? $query->whereNull('customers.assigned_user_id')
                : $query->whereHas('assignee', fn ($u) => $u->where('ulid', $assigned));
        }

        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('customers.status', $s))
            ->when($filters['gender'] ?? null, fn ($q, $g) => $q->where('customers.gender', $g))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('customers.source', $v))
            ->when($filters['segment'] ?? null, fn ($q, $segment) => self::segment($q, $segment))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('customers.type', $v))
            ->when($filters['birth_month'] ?? null, fn ($q, $m) => $q->whereMonth('customers.birthday', (int) $m))
            ->when($filters['tag'] ?? null, fn ($q, $t) => $q->whereJsonContains('customers.tags', $t))
            ->when($filters['created_from'] ?? null, fn ($q, $d) => $q->whereDate('customers.created_at', '>=', $d))
            ->when($filters['created_to'] ?? null, fn ($q, $d) => $q->whereDate('customers.created_at', '<=', $d))
            ->when(! empty($filters['debt']), fn ($q) => $q->having('debt', '>', 0))
            ->when(! empty($filters['overdue']), fn ($q) => $q->having('overdue', '>', 0))
            ->when(isset($filters['spent_min']), fn ($q) => $q->having('total_spent', '>=', $filters['spent_min']))
            ->when(isset($filters['spent_max']), fn ($q) => $q->having('total_spent', '<=', $filters['spent_max']))
            ->when(isset($filters['orders_min']), fn ($q) => $q->having('orders_count', '>=', (int) $filters['orders_min']))
            ->when($filters['last_order_from'] ?? null, fn ($q, $d) => $q->having('last_order_at', '>=', $d.' 00:00:00'))
            ->when($filters['last_order_to'] ?? null, fn ($q, $d) => $q->having('last_order_at', '<=', $d.' 23:59:59'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'group_id' => ['nullable', 'string', 'max:40'],
            'assigned_user_id' => ['nullable', 'string', 'max:40'],
            'birthday' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(Customer::GENDERS)],
            'tax_code' => ['nullable', 'string', 'max:20'],
            'type' => ['sometimes', Rule::in(Customer::TYPES)],
            'source' => ['nullable', Rule::in(Customer::SOURCES)],
            'invoice_name' => ['nullable', 'string', 'max:200'],
            'invoice_address' => ['nullable', 'string', 'max:500'],
            'invoice_email' => ['nullable', 'email', 'max:150'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(Customer::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], ['code.regex' => 'Mã khách chỉ gồm chữ, số, dấu chấm, gạch ngang, gạch dưới.']);
    }
}
