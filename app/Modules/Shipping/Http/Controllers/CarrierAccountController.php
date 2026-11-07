<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Shipping\Connectors\CarrierRegistry;
use App\Modules\Shipping\Models\CarrierAccount;
use App\Modules\Shipping\Services\CarrierAccountService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Kết nối vận chuyển": the carriers ECSO can talk to, and the shop's own accounts at them. */
class CarrierAccountController extends Controller
{
    public function __construct(private readonly CarrierRegistry $carriers, private readonly CarrierAccountService $service) {}

    public function catalog(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $available = array_map(fn (string $key) => [
            'carrier' => $key,
            'label' => $this->carriers->get($key)->label(),
            'fields' => $this->carriers->get($key)->credentialFields(),
            'available' => true,
            'note' => null,
        ], $this->carriers->keys());
        $upcoming = collect(CarrierRegistry::UPCOMING)->reject(fn ($c, $key) => $this->carriers->has($key))
            ->map(fn ($c, $key) => ['carrier' => $key, 'label' => $c['label'], 'fields' => [], 'available' => false, 'note' => $c['note']])->values()->all();

        return response()->json(['data' => [...$available, ...$upcoming]]);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $warehouses = Warehouse::query()->get(['id', 'ulid', 'code', 'name'])->keyBy('id');

        return response()->json(['data' => CarrierAccount::query()->where('status', '!=', CarrierAccount::DISCONNECTED)->orderBy('id')->get()
            ->map(fn (CarrierAccount $a) => $this->present($a, $warehouses))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('update', app(TenantContext::class)->tenant());
        $data = $request->validate([
            'carrier' => ['required', 'string', Rule::in($this->carriers->keys())],
            'environment' => ['nullable', Rule::in([CarrierAccount::SANDBOX, CarrierAccount::PRODUCTION])],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);
        $fields = array_keys($this->carriers->get($data['carrier'])->credentialFields());
        $account = $this->service->connect($data['carrier'], array_intersect_key($data['credentials'], array_flip($fields)), $data['environment'] ?? CarrierAccount::PRODUCTION);

        return response()->json(['data' => $this->present($account)], 201);
    }

    public function update(Request $request, CarrierAccount $account): JsonResponse
    {
        $this->authorize('update', app(TenantContext::class)->tenant());
        $data = $request->validate(['pickups' => ['required', 'array'], 'pickups.*' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->present($this->service->setPickups($account, $data['pickups']))]);
    }

    public function check(CarrierAccount $account): JsonResponse
    {
        $this->authorize('update', app(TenantContext::class)->tenant());

        return response()->json(['data' => $this->present($this->service->check($account))]);
    }

    public function destroy(CarrierAccount $account): JsonResponse
    {
        $this->authorize('update', app(TenantContext::class)->tenant());
        $this->service->disconnect($account);

        return response()->json(['data' => ['disconnected' => true]]);
    }

    private function present(CarrierAccount $account, $warehouses = null): array
    {
        $warehouses ??= Warehouse::query()->get(['id', 'ulid', 'code', 'name'])->keyBy('id');
        $canManage = app(TenantContext::class)->can('settings.manage');

        return [
            'id' => $account->ulid,
            'carrier' => $account->carrier,
            'carrier_label' => $this->carriers->has($account->carrier) ? $this->carriers->get($account->carrier)->label() : $account->carrier,
            'name' => $account->name,
            'external_account' => $account->external_account,
            'environment' => $account->environment,
            'status' => $account->status,
            'locations' => $account->locations ?? [],
            // Every warehouse, with the carrier's pickup point chosen for it (null = the account's first point).
            'pickups' => $warehouses->map(fn ($w) => ['warehouse' => ['id' => $w->ulid, 'code' => $w->code, 'name' => $w->name], 'pickup' => ($account->pickups ?? [])[$w->id] ?? null])->values(),
            // Only who can change the connection sees where the carrier sends its updates.
            'webhook_url' => $canManage ? route('carrier-webhooks.receive', ['token' => $account->webhook_token]) : null,
            'last_verified_at' => $account->last_verified_at?->toIso8601String(),
            'last_event_at' => $account->last_event_at?->toIso8601String(),
            'last_error' => $account->last_error,
        ];
    }
}
