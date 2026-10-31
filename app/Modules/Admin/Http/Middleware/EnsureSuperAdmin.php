<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin console access: super admin AND (when required) two-factor authentication passed in this session.
 * The 2FA endpoints themselves use `super-admin:setup`, which skips the 2FA check.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_super_admin === true, 403, 'Chỉ dành cho quản trị hệ thống.');

        if ($mode !== 'setup' && TwoFactorService::required($user)) {
            if (! TwoFactorService::enabled($user)) {
                return response()->json(['message' => 'Cần bật xác thực hai bước trước khi dùng trang quản trị.', 'code' => 'TWO_FACTOR_SETUP_REQUIRED'], 403);
            }
            if (! TwoFactorService::passed($request)) {
                return response()->json(['message' => 'Cần nhập mã xác thực hai bước.', 'code' => 'TWO_FACTOR_REQUIRED'], 403);
            }
        }

        return $next($request);
    }
}
