<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Billing\Support\Entitlements;
use App\Modules\Tenancy\Exceptions\NoTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Closure;

/**
 * Holds the tenant the current request or job acts for.
 * The tenant is only ever set from a server-side lookup (membership, marketplace account mapping),
 * never from a tenant_id supplied by the client.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantUser $membership = null;

    /** @var array<int, string>|null */
    private ?array $permissions = null;

    public function set(Tenant $tenant, ?TenantUser $membership = null): void
    {
        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->permissions = null;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
        $this->permissions = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new NoTenantContext;
    }

    public function id(): int
    {
        return $this->tenant()->id;
    }

    public function membership(): ?TenantUser
    {
        return $this->membership;
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        if ($this->membership === null) {
            return [];
        }

        return $this->permissions ??= $this->membership->role->permissionKeys();
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function entitlements(): Entitlements
    {
        return Entitlements::for($this->tenant());
    }

    /**
     * Run a callback (job, command, webhook handler) inside a tenant, restoring the previous context afterwards.
     */
    public static function run(Tenant $tenant, Closure $callback, ?TenantUser $membership = null): mixed
    {
        $context = app(self::class);
        $previous = [$context->tenant, $context->membership];

        $context->set($tenant, $membership);

        try {
            return $callback();
        } finally {
            [$tenant, $membership] = $previous;
            $tenant ? $context->set($tenant, $membership) : $context->clear();
        }
    }
}
