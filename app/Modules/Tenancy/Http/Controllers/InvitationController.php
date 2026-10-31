<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\Role;
use App\Modules\Tenancy\Http\Resources\InvitationResource;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvitationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TenantInvitation::class);

        return InvitationResource::collection(
            TenantInvitation::query()->with(['role', 'inviter'])->latest()->limit(100)->get()
        );
    }

    public function store(Request $request, InvitationService $service): JsonResponse
    {
        $this->authorize('create', TenantInvitation::class);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', 'string'],
        ]);

        $role = Role::query()->visibleToCurrentTenant()->where('key', $data['role'])->first();
        abort_if($role === null, 422, 'Vai trò không hợp lệ.');

        $invitation = $service->invite($data['email'], $role, $request->user());

        return (new InvitationResource($invitation->load(['role', 'inviter'])))->response()->setStatusCode(201);
    }

    public function destroy(TenantInvitation $invitation, InvitationService $service): JsonResponse
    {
        $this->authorize('delete', $invitation);
        $service->revoke($invitation);

        return response()->json(null, 204);
    }

    /**
     * Public preview so the invitee sees which business invited them before signing in.
     */
    public function show(string $token, InvitationService $service): JsonResponse
    {
        $invitation = $service->findByToken($token);
        abort_if($invitation === null || ! $invitation->isPending(), 404, 'Lời mời không hợp lệ hoặc đã hết hạn.');

        return response()->json(['data' => [
            'tenant' => $invitation->tenant->name,
            'email' => $invitation->email,
            'role' => $invitation->role->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, string $token, InvitationService $service): JsonResponse
    {
        $membership = $service->accept($token, $request->user());
        $tenant = $membership->tenant;

        return response()->json(['data' => new TenantResource($tenant)]);
    }
}
