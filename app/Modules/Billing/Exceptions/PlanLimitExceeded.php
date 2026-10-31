<?php

namespace App\Modules\Billing\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class PlanLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $feature, public readonly int $limit)
    {
        parent::__construct("Gói dịch vụ hiện tại giới hạn {$limit} cho {$feature}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PLAN_LIMIT_EXCEEDED',
            'feature' => $this->feature,
            'limit' => $this->limit,
        ], 403);
    }
}
