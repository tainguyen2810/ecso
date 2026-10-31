<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\SystemMonitor;
use Illuminate\Http\JsonResponse;

class AdminMonitoringController extends Controller
{
    public function __invoke(SystemMonitor $monitor): JsonResponse
    {
        $snapshot = $monitor->snapshot();

        return response()->json(['data' => [...$snapshot, 'problems' => $monitor->problems($snapshot)]]);
    }
}
