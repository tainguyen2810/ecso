<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Services\SystemMonitor;
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
            'queue_backlog' => array_sum(array_filter(app(SystemMonitor::class)->snapshot()['queues'], 'is_int')),
            // Counts only: no tenant business content.
            'shops_connected' => DB::table('marketplace_accounts')->where('status', 'connected')->count(),
            'orders_processed' => DB::table('orders')->count(),
            'mrr' => round((float) DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->whereIn('subscriptions.status', ['active', 'past_due'])->where('subscriptions.current_period_end', '>', now())
                ->sum(DB::raw("CASE WHEN subscriptions.billing_cycle = 'yearly' THEN plans.price_yearly / 12 ELSE plans.price_monthly END")), 2),
            'webhook_failures' => DB::table('webhook_events')->whereIn('status', ['failed', 'rejected'])->where('received_at', '>=', now()->subDay())->count(),
        ]]);
    }
}
