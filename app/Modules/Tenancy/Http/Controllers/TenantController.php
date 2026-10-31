<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    /**
     * Tenants the user belongs to (for the tenant switcher). Not tenant-scoped by design.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = Tenant::query()
            ->join('tenant_users', 'tenant_users.tenant_id', '=', 'tenants.id')
            ->join('roles', 'roles.id', '=', 'tenant_users.role_id')
            ->where('tenant_users.user_id', $request->user()->id)
            ->where('tenant_users.status', 'active')
            ->orderBy('tenants.name')
            ->get(['tenants.*', 'roles.key as role_key']);

        return TenantResource::collection($tenants);
    }

    public function store(Request $request, TenantService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $tenant = $service->create($request->user(), $data);

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    public function current(TenantContext $context): JsonResponse
    {
        $membership = $context->membership();

        return response()->json([
            'data' => [
                'tenant' => new TenantResource($context->tenant()),
                'role' => ['key' => $membership->role->key, 'name' => $membership->role->name],
                'permissions' => $context->permissions(),
                'entitlements' => $context->entitlements()->toArray(),
            ],
        ]);
    }

    public function update(Request $request, TenantContext $context, AuditLogger $audit): TenantResource
    {
        $tenant = $context->tenant();
        $this->authorize('update', $tenant);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $tenant->fill($data);
        $changes = $tenant->getDirty();
        $tenant->save();
        $audit->record('tenant.updated', $tenant, $changes);

        return new TenantResource($tenant);
    }
}
