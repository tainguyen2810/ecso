<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            ->with(['owner:id,name,email', 'subscription.plan:id,key,name'])
            ->withCount(['memberships as members_count'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'data' => $tenants->getCollection()->map(fn (Tenant $t) => [
                'id' => $t->ulid,
                'name' => $t->name,
                'status' => $t->status,
                'owner' => $t->owner ? ['name' => $t->owner->name, 'email' => $t->owner->email] : null,
                'plan' => $t->subscription?->plan?->name,
                'members_count' => $t->members_count,
                'created_at' => $t->created_at->toIso8601String(),
            ]),
            'meta' => ['total' => $tenants->total(), 'page' => $tenants->currentPage(), 'last_page' => $tenants->lastPage()],
        ]);
    }
}
