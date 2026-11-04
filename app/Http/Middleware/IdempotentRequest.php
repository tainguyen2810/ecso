<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key for state-changing requests (audit round A). A client that sends the same key again (double click,
 * network retry) gets the first answer back instead of a second receipt, transfer or purchase order.
 *
 *  - No header, or a GET: the request runs as usual.
 *  - First request with a key: runs; a 2xx answer is stored for 48 hours, anything else frees the key so the user
 *    can correct the form and send again.
 *  - Same key, same request, finished: the stored answer is replayed (header Idempotent-Replayed: true).
 *  - Same key while the first one still runs: 409. Same key with a different request: 422.
 *
 * Keys are per tenant and per user. Must run after the tenant middleware.
 */
class IdempotentRequest
{
    public const HEADER = 'Idempotency-Key';

    /** A first request that never finished (process killed) stops blocking its key after this long. */
    private const STALE_SECONDS = 120;

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));
        if ($key === '' || $request->isMethodSafe() || ! $this->context->has() || $request->user() === null) {
            return $next($request);
        }
        if (strlen($key) > 100 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return response()->json(['message' => 'Idempotency-Key không hợp lệ.'], 400);
        }

        $scope = ['tenant_id' => $this->context->id(), 'user_id' => $request->user()->getKey(), 'key' => $key];
        $route = substr($request->method().' '.$request->path(), 0, 191);
        $hash = hash('sha256', $route."\n".$request->getContent());

        if (! $this->claim($scope, $route, $hash)) {
            return $this->answerRepeat($scope, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->table()->where($scope)->delete();
            throw $e;
        }

        if ($response->isSuccessful()) {
            $this->table()->where($scope)->update(['status_code' => $response->getStatusCode(), 'response_body' => (string) $response->getContent()]);
        } else {
            $this->table()->where($scope)->delete();
        }

        return $response;
    }

    /** @param  array{tenant_id: int, user_id: int|string, key: string}  $scope */
    private function claim(array $scope, string $route, string $hash): bool
    {
        // @tenant-bypass: the tenant id is part of $scope, set from the resolved context.
        try {
            $this->table()->insert($scope + ['route' => $route, 'request_hash' => $hash, 'created_at' => now()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            $stale = $this->table()->where($scope)->whereNull('status_code')->where('created_at', '<', now()->subSeconds(self::STALE_SECONDS))->delete();
            if ($stale > 0) {
                return $this->claim($scope, $route, $hash);
            }

            return false;
        }
    }

    /** @param  array{tenant_id: int, user_id: int|string, key: string}  $scope */
    private function answerRepeat(array $scope, string $hash): Response
    {
        $row = $this->table()->where($scope)->first();
        if ($row === null) {
            return response()->json(['message' => 'Yêu cầu trước vừa kết thúc, hãy thử lại.'], 409);
        }
        if (! hash_equals($row->request_hash, $hash)) {
            return response()->json(['message' => 'Idempotency-Key này đã được dùng cho một yêu cầu khác.'], 422);
        }
        if ($row->status_code === null) {
            return response()->json(['message' => 'Yêu cầu trước vẫn đang được xử lý, hãy đợi giây lát.'], 409);
        }

        return response((string) $row->response_body, (int) $row->status_code, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }

    private function table(): Builder
    {
        return DB::table('idempotency_keys');
    }
}
