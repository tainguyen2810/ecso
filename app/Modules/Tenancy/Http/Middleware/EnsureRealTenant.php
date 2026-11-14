<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A2: a sample store holds fake data, so it may not connect a real marketplace shop, carrier, e-invoice or Zalo
 * account (orders, stock and messages would reach real people). Runs after "tenant".
 */
class EnsureRealTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->has() && $this->context->tenant()->is_demo) {
            return response()->json([
                'message' => 'Cửa hàng mẫu chỉ để xem thử, không kết nối được tài khoản thật. Chuyển về cửa hàng của bạn để kết nối.',
                'code' => 'DEMO_STORE',
            ], 403);
        }

        return $next($request);
    }
}
