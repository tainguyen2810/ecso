<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Http\Resources\CustomerGroupResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer groups and the terms their customers buy on. Viewing follows customers.view, changes customers.manage.
 */
class CustomerGroupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        return CustomerGroupResource::collection(CustomerGroup::query()->withCount('customers')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $data = $this->validated($request, null);
        $group = new CustomerGroup($data);
        $group->code = filled($data['code'] ?? null) ? $data['code'] : $this->autoCode($data['name']);
        $group->save();
        $this->audit->record('customer_group.created', $group, ['code' => $group->code]);

        return (new CustomerGroupResource($group->loadCount('customers')))->response()->setStatusCode(201);
    }

    public function update(Request $request, CustomerGroup $customerGroup): CustomerGroupResource
    {
        $this->authorize('update', Customer::class);
        $data = $this->validated($request, $customerGroup);
        if (blank($data['code'] ?? null)) {
            unset($data['code']);
        }
        $customerGroup->fill($data)->save();
        $this->audit->record('customer_group.updated', $customerGroup, ['code' => $customerGroup->code]);

        return new CustomerGroupResource($customerGroup->loadCount('customers'));
    }

    public function destroy(CustomerGroup $customerGroup): JsonResponse
    {
        $this->authorize('delete', Customer::class);
        $count = $customerGroup->customers()->count();
        if ($count > 0) {
            throw ValidationException::withMessages(['group' => "Nhóm còn {$count} khách. Chuyển khách sang nhóm khác trước khi xóa."]);
        }
        $customerGroup->delete();
        $this->audit->record('customer_group.deleted', $customerGroup, ['code' => $customerGroup->code]);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CustomerGroup $group): array
    {
        $unique = fn (string $column) => Rule::unique('customer_groups', $column)
            ->where('tenant_id', app(TenantContext::class)->id())
            ->ignore($group?->id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', $unique('name')],
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', $unique('code')],
            'description' => ['nullable', 'string', 'max:500'],
            'price_tier' => ['required', Rule::in(CustomerGroup::PRICE_TIERS)],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', Rule::in(OrderPayment::METHODS)],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
            'auto_min_spent' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'auto_min_orders' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], [
            'name.unique' => 'Đã có nhóm cùng tên.',
            'code.unique' => 'Mã nhóm đã được dùng.',
            'code.regex' => 'Mã nhóm chỉ gồm chữ, số, dấu chấm, gạch ngang, gạch dưới.',
        ]);
        $data['discount_percent'] ??= 0;

        return $data;
    }

    private function autoCode(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, ''), 28, '')) ?: 'NHOM';
        $code = $base;
        for ($i = 2; CustomerGroup::query()->where('code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }
}
