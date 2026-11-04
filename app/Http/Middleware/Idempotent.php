<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write request safe to retry. When the client sends an `Idempotency-Key` header, the first successful
 * response is stored and every later request with the same key (same user and business) gets that response back,
 * marked `Idempotent-Replayed: true`, without running again: one sale, one payment, one stock movement.
 *
 *  - same key while the first request is still running: 409, try again in a moment;
 *  - same key with a different body: 422 (the key belongs to another request);
 *  - a failed request (4xx/5xx) is not stored, so the user can fix the input and send again with the same key;
 *  - a key whose request never finished (process killed) is released after STALE_SECONDS.
 *
 * Requests without the header run as before.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public const STALE_SECONDS = 120;

    public const KEEP_DAYS = 2;

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER));
        if ($key === '' || ! $this->context->has() || $request->user() === null) {
            return $next($request);
        }
        if (strlen($key) > 100) {
            return response()->json(['message' => 'Idempotency-Key quá dài (tối đa 100 ký tự).'], 422);
        }

        $scope = ['tenant_id' => $this->context->id(), 'user_id' => $request->user()->id, 'key' => $key];
        $route = $request->method().' '.$request->path();
        $hash = hash('sha256', $route."\n".$request->getContent());

        if ($claimed = $this->claim($scope, $route, $hash)) {
            return $claimed;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where($scope)->delete();
            throw $e;
        }

        if ($response->isSuccessful()) {
            DB::table('idempotency_keys')->where($scope)->update(['status_code' => $response->getStatusCode(), 'response' => $response->getContent()]);
        } else {
            DB::table('idempotency_keys')->where($scope)->delete();
        }

        return $response;
    }

    /**
     * Reserves the key for this request. Returns a response when the request must not run (replay or conflict).
     *
     * @param  array{tenant_id: int, user_id: int, key: string}  $scope
     */
    private function claim(array $scope, string $route, string $hash): ?Response
    {
        try {
            DB::table('idempotency_keys')->insert([...$scope, 'route' => $route, 'request_hash' => $hash, 'created_at' => now()]);

            return null;
        } catch (UniqueConstraintViolationException) {
            // Used before: fall through.
        }

        $row = DB::table('idempotency_keys')->where($scope)->first();
        if ($row === null) {
            return $this->claim($scope, $route, $hash); // released between the insert and the read
        }
        if ($row->request_hash !== $hash) {
            return response()->json(['message' => 'Mã chống gửi trùng này đã dùng cho một yêu cầu khác.'], 422);
        }
        if ($row->status_code === null) {
            if (now()->diffInSeconds($row->created_at, true) > self::STALE_SECONDS
                && DB::table('idempotency_keys')->where('id', $row->id)->whereNull('status_code')->delete() === 1) {
                return $this->claim($scope, $route, $hash);
            }

            return response()->json(['message' => 'Yêu cầu này đang được xử lý, vui lòng đợi giây lát.'], 409);
        }

        return response((string) $row->response, (int) $row->status_code, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }
}
