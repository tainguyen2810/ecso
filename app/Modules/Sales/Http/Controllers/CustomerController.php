<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Sales\Http\Resources\CustomerAddressResource;
use App\Modules\Sales\Http\Resources\CustomerNoteResource;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerLedger;
use App\Modules\Sales\Services\CustomerService;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(Customer::STATUSES)],
            'debt' => ['nullable', 'boolean'],
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
            'sort' => ['nullable', Rule::in(['newest', 'name', 'spent', 'debt', 'recent', 'orders'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = $this->filtered(Customer::query()->withStats()->with(['customerGroup', 'assignee']), $filters);
        match ($filters['sort'] ?? 'newest') {
            'name' => $query->orderBy('customers.name'),
            'spent' => $query->orderByDesc('total_spent'),
            'debt' => $query->orderByDesc('debt'),
            'recent' => $query->orderByDesc('last_order_at'),
            'orders' => $query->orderByDesc('orders_count'),
            default => $query->orderByDesc('customers.id'),
        };

        return CustomerResource::collection($query->paginate((int) ($filters['per_page'] ?? 30)));
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
            'orders' => OrderResource::collection($customer->orders()->with('channel')->orderByDesc('placed_at')->limit(50)->get()),
            'payments' => PaymentResource::collection($customer->payments()->with(['order', 'creator'])->orderByDesc('paid_at')->limit(20)->get()),
            'addresses' => CustomerAddressResource::collection($customer->addresses()->orderBy('id')->get()),
            'notes' => CustomerNoteResource::collection($customer->notes()->with('author:id,name')->latest('id')->limit(100)->get()),
        ]])->response();
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
        $this->authorize('update', Customer::class);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
            'action' => ['required', Rule::in(CustomerService::BULK_ACTIONS)],
            'value' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json($customers->bulk(Customer::query()->whereIn('ulid', $data['ids'])->get(), $data['action'], $data['value'] ?? null));
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    private function filtered(Builder $query, array $filters): Builder
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
            ->when($filters['birth_month'] ?? null, fn ($q, $m) => $q->whereMonth('customers.birthday', (int) $m))
            ->when($filters['tag'] ?? null, fn ($q, $t) => $q->whereJsonContains('customers.tags', $t))
            ->when($filters['created_from'] ?? null, fn ($q, $d) => $q->whereDate('customers.created_at', '>=', $d))
            ->when($filters['created_to'] ?? null, fn ($q, $d) => $q->whereDate('customers.created_at', '<=', $d))
            ->when(! empty($filters['debt']), fn ($q) => $q->having('debt', '>', 0))
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
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(Customer::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['code.regex' => 'Mã khách chỉ gồm chữ, số, dấu chấm, gạch ngang, gạch dưới.']);
    }
}
