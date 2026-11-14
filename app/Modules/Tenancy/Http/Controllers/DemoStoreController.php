<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\DemoStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A2: the user's own sample store. Not tenant-scoped: it is made from, and deleted from, any business. */
class DemoStoreController extends Controller
{
    public function store(Request $request, DemoStoreService $demo): JsonResponse
    {
        $tenant = $demo->createFor($request->user());

        return (new TenantResource($tenant))->response()->setStatusCode($tenant->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $tenant, DemoStoreService $demo): JsonResponse
    {
        $model = Tenant::query()->where('ulid', $tenant)->where('owner_user_id', $request->user()->id)->where('is_demo', true)->first();
        abort_if($model === null, 404);
        $demo->purge($model, $request->user()->id);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
