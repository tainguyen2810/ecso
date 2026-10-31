<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Tenant is only a selector: the tenant is accepted only if the authenticated user has an
 * active membership in it. Unknown tenant and "not a member" answer the same 404 so tenant
 * existence is not leaked.
 */
class ResolveTenant
{
    public const HEADER = 'X-Tenant';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ulid = (string) $request->header(self::HEADER, '');
        $user = $request->user();

        $tenant = $ulid !== '' ? Tenant::query()->where('ulid', $ulid)->first() : null;

        // @tenant-bypass membership lookup happens before a context exists.
        $membership = $tenant && $user
            ? TenantUser::withoutGlobalScope(TenantScope::class)
                ->with('role.permissions')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('status', TenantUser::STATUS_ACTIVE)
                ->first()
            : null;

        if ($membership === null) {
            return response()->json(['message' => 'Không tìm thấy doanh nghiệp.', 'code' => 'TENANT_NOT_FOUND'], 404);
        }

        if (! $tenant->isActive()) {
            return response()->json(['message' => 'Doanh nghiệp đang bị tạm khóa.', 'code' => 'TENANT_SUSPENDED'], 403);
        }

        $this->context->set($tenant, $membership);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
