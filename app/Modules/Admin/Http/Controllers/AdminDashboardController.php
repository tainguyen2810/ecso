<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Platform-level aggregates only. No tenant business data (orders, customers) is exposed here.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'tenants_total' => Tenant::query()->count(),
            'tenants_new_30d' => Tenant::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'tenants_active' => Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->count(),
            'tenants_suspended' => Tenant::query()->where('status', Tenant::STATUS_SUSPENDED)->count(),
            'users_total' => User::query()->count(),
            'users_locked' => User::query()->where('status', User::STATUS_LOCKED)->count(),
            'subscriptions_by_plan' => DB::table('subscriptions')
                ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->groupBy('plans.key')
                ->pluck(DB::raw('count(*) as total'), 'plans.key'),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'queue_backlog' => DB::table('jobs')->count(),
            // Filled in by later phases (marketplace, orders, billing).
            'shops_connected' => null,
            'orders_processed' => null,
            'mrr' => null,
            'webhook_failures' => null,
        ]]);
    }
}
