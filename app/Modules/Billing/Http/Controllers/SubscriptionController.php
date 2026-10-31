<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        abort_unless($context->can('billing.view'), 403);

        $subscription = Subscription::query()->with('plan')->first();

        return response()->json(['data' => [
            'plan' => $subscription ? ['key' => $subscription->plan->key, 'name' => $subscription->plan->name] : null,
            'status' => $subscription?->status,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'entitlements' => $context->entitlements()->toArray(),
            'usage' => [
                'limit.users' => TenantUser::query()->count(),
            ],
            'plans' => Plan::query()->where('is_public', true)->orderBy('sort')->with('features')->get()->map(fn (Plan $plan) => [
                'key' => $plan->key,
                'name' => $plan->name,
                'price_monthly' => (float) $plan->price_monthly,
                'features' => $plan->features->mapWithKeys(fn ($f) => [$f->key => [
                    'enabled' => (bool) $f->pivot->getAttribute('enabled'),
                    'limit' => $f->pivot->getAttribute('limit_value'),
                ]]),
            ]),
        ]]);
    }
}
