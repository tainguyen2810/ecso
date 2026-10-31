<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\Role;
use App\Modules\Tenancy\Http\Resources\MemberResource;
use App\Modules\Tenancy\Http\Resources\RoleResource;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\MembershipService;
use App\Modules\Tenancy\Services\RoleGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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

    public function update(Request $request, TenantUser $member, MembershipService $service): MemberResource
    {
        $this->authorize('update', $member);

        $data = $request->validate([
            'role' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in([TenantUser::STATUS_ACTIVE, TenantUser::STATUS_DISABLED])],
        ]);

        if (isset($data['role'])) {
            $role = Role::query()->visibleToCurrentTenant()->where('key', $data['role'])->first();
            abort_if($role === null, 422, 'Vai trò không hợp lệ.');
            $member = $service->changeRole($member, $role);
        }

        if (isset($data['status'])) {
            $member = $service->changeStatus($member, $data['status']);
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
