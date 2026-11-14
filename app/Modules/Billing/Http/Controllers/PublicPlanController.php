<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\PlanService;
use Illuminate\Http\JsonResponse;

/**
 * A1: the public plans (no login), for the marketing website (WordPress) to show the same prices and limits as the
 * app. Only plans marked public, in the admin's order.
 */
class PublicPlanController extends Controller
{
    public function __invoke(PlanService $plans): JsonResponse
    {
        $rows = Plan::query()->where('is_public', true)->with('features')->orderBy('sort')->orderBy('id')->get()
            ->map(function (Plan $plan) use ($plans) {
                $row = $plans->present($plan);
                unset($row['is_public']);

                return $row;
            });

        return response()->json(['data' => $rows, 'currency' => 'VND', 'signup_url' => rtrim((string) config('app.url'), '/').'/register'])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
