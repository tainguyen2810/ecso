<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Feature;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A1: Admin → Gói dịch vụ. */
class AdminPlanController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(): JsonResponse
    {
        $using = Subscription::withoutGlobalScopes()->selectRaw('plan_id, COUNT(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id');

        return response()->json([
            'data' => Plan::query()->with('features')->orderBy('sort')->orderBy('id')->get()->map(fn (Plan $p) => [
                ...$this->plans->present($p),
                'tenants' => (int) ($using[$p->id] ?? 0),
                'deletable' => $p->key !== Plan::FREE && ! $this->plans->inUse($p),
                'is_default' => $p->key === Plan::FREE,
            ]),
            'features' => Feature::query()->orderBy('id')->get(['key', 'type', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{1,49}$/', Rule::unique('plans', 'key')],
            ...$this->rules(required: true),
        ], ['key.regex' => 'Mã gói chỉ gồm chữ thường không dấu, số, gạch ngang, 2–50 ký tự.']);

        return response()->json(['data' => $this->plans->present($this->plans->create($data))], 201);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $model = Plan::query()->where('key', $plan)->firstOrFail();

        return response()->json(['data' => $this->plans->present($this->plans->update($model, $request->validate($this->rules(required: false))))]);
    }

    public function destroy(string $plan): JsonResponse
    {
        $this->plans->delete(Plan::query()->where('key', $plan)->firstOrFail());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['keys' => ['required', 'array', 'max:50'], 'keys.*' => ['string', 'distinct']]);
        $this->plans->reorder(array_values($data['keys']));

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $required): array
    {
        $need = $required ? 'required' : 'sometimes';

        return [
            'name' => [$need, 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'badge' => ['nullable', 'string', 'max:40'],
            'highlights' => ['nullable', 'array', 'max:8'],
            'highlights.*' => ['nullable', 'string', 'max:120'],
            'price_monthly' => [$need, 'numeric', 'min:0', 'max:999999999999'],
            'price_yearly' => [$need, 'numeric', 'min:0', 'max:999999999999'],
            'trial_days' => [$need, 'integer', 'min:0', 'max:365'],
            'is_public' => [$need, 'boolean'],
            'features' => ['sometimes', 'array'],
            'features.*.enabled' => ['required', 'boolean'],
            'features.*.limit' => ['nullable', 'integer', 'min:0', 'max:4000000000'],
        ];
    }
}
