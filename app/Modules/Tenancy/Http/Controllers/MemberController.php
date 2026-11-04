<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Http\Resources\MemberResource;
use App\Modules\Tenancy\Http\Resources\RoleResource;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\MembershipService;
use App\Modules\Tenancy\Services\RoleGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TenantUser::class);

        return MemberResource::collection(
            TenantUser::query()->with(['user', 'role'])->orderBy('created_at')->get()
        );
    }

    /**
     * Roles the current user is allowed to assign.
     */
    public function roles(RoleGuard $guard): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TenantUser::class);

        $roles = Role::query()->visibleToCurrentTenant()->with('permissions')->orderByDesc('rank')->get()
            ->map(fn (Role $role) => $role->setAttribute('assignable', $guard->canAssign($role)));

        return RoleResource::collection($roles)->additional([
            'assignable' => $roles->filter->assignable->pluck('key')->values(),
        ]);
    }

    public function update(Request $request, TenantUser $member, MembershipService $service, AuditLogger $audit): MemberResource
    {
        $this->authorize('update', $member);

        $data = $request->validate([
            'role' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in([TenantUser::STATUS_ACTIVE, TenantUser::STATUS_DISABLED])],
            // Warehouses the member may move stock in; an empty list means every warehouse.
            'warehouses' => ['sometimes', 'array', 'max:100'],
            'warehouses.*' => ['string', 'distinct'],
        ]);

        if (isset($data['role'])) {
            $role = Role::query()->visibleToCurrentTenant()->where('key', $data['role'])->first();
            abort_if($role === null, 422, 'Vai trò không hợp lệ.');
            $member = $service->changeRole($member, $role);
        }

        if (isset($data['status'])) {
            $member = $service->changeStatus($member, $data['status']);
        }

        if (array_key_exists('warehouses', $data)) {
            $ids = Warehouse::query()->whereIn('ulid', $data['warehouses'])->pluck('id');
            abort_if($ids->count() !== count($data['warehouses']), 422, 'Kho không tồn tại.');
            DB::transaction(function () use ($member, $ids) {
                DB::table('member_warehouses')->where('tenant_user_id', $member->id)->delete();
                DB::table('member_warehouses')->insert($ids->map(fn ($id) => ['tenant_id' => $member->tenant_id, 'tenant_user_id' => $member->id, 'warehouse_id' => $id])->all());
            });
            $audit->record('member.warehouses_changed', $member, ['warehouses' => $data['warehouses']]);
        }

        return new MemberResource($member->load(['user', 'role']));
    }

    public function destroy(TenantUser $member, MembershipService $service): JsonResponse
    {
        $this->authorize('delete', $member);
        $service->remove($member);

        return response()->json(null, 204);
    }
}
