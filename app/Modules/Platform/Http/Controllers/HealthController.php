<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Detailed readiness, protected by HEALTH_TOKEN so internals are not public.
     */
    public function ready(Request $request): JsonResponse
    {
        $token = (string) config('ecso.health_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Health-Token')), 404);

        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
            'cache' => $this->check(fn () => Cache::put('health', 1, 10)),
            'scheduler' => $this->check(fn () => Cache::get('ecso:scheduler:heartbeat') >= now()->subMinutes(2)->timestamp
                ?: throw new \RuntimeException('no heartbeat')),
        ];
        $checks['queue_backlog'] = DB::table('jobs')->count();
        $checks['failed_jobs'] = DB::table('failed_jobs')->count();

        $healthy = collect($checks)->only(['database', 'redis', 'cache'])->every(fn ($c) => $c === 'ok');

        return response()->json(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            return 'fail: '.class_basename($e);
        }
    }
}
