<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerService;
use App\Modules\Sales\Support\PhoneNumber;
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
            'group' => ['nullable', 'string', 'max:50'],
            'debt' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'name', 'spent', 'debt', 'recent'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Customer::query()->withStats();
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $digits = PhoneNumber::normalize($q);
            $query->where(function ($w) use ($q, $digits) {
                $w->where('customers.name', 'like', "%{$q}%")->orWhere('customers.code', 'like', "%{$q}%");
                if ($digits !== null && strlen($digits) >= 9) {
                    $w->orWhere('customers.phone_hash', PhoneNumber::hash($digits));
                } elseif ($digits !== null && strlen($digits) === 4) {
                    $w->orWhere('customers.phone_last4', $digits);
                }
            });
        }
        if ($group = $filters['group'] ?? null) {
            $query->where('customers.group', $group);
        }
        if ($request->boolean('debt')) {
            $query->having('debt', '>', 0);
        }
        match ($filters['sort'] ?? 'newest') {
            'name' => $query->orderBy('customers.name'),
            'spent' => $query->orderByDesc('total_spent'),
            'debt' => $query->orderByDesc('debt'),
            'recent' => $query->orderByDesc('last_order_at'),
            default => $query->orderByDesc('customers.id'),
        };

        return CustomerResource::collection($query->paginate((int) ($filters['per_page'] ?? 30)));
    }

    public function groups(): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        return response()->json(['data' => Customer::query()->whereNotNull('group')->distinct()->orderBy('group')->pluck('group')]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $stats = Customer::query()->withStats()->whereKey($customer->id)->firstOrFail();

        return (new CustomerResource($stats))->additional(['data' => [
            'orders' => OrderResource::collection($customer->orders()->with('channel')->orderByDesc('placed_at')->limit(20)->get()),
            'payments' => PaymentResource::collection($customer->payments()->with(['order', 'creator'])->orderByDesc('paid_at')->limit(20)->get()),
        ]])->response();
    }

    public function store(Request $request, CustomerService $customers): JsonResponse
    {
        $this->authorize('create', Customer::class);

        return (new CustomerResource($customers->create($this->validated($request))))->response()->setStatusCode(201);
    }

    public function update(Request $request, Customer $customer, CustomerService $customers): CustomerResource
    {
        $this->authorize('update', $customer);

        return new CustomerResource($customers->update($customer, $this->validated($request)));
    }

    public function destroy(Customer $customer, CustomerService $customers): JsonResponse
    {
        $this->authorize('delete', $customer);
        $customers->delete($customer);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'group' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
