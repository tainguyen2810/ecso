<?php

namespace App\Modules\Access\Models;

use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * tenant_id NULL = system role shared by all tenants. Not tenant-scoped by global scope because
 * system roles must be visible to every tenant; use visibleToCurrentTenant() instead.
 */
class Role extends Model
{
    public const OWNER = 'TENANT_OWNER';

    public const ADMIN = 'TENANT_ADMIN';

    public const MANAGER = 'MANAGER';

    public const WAREHOUSE_STAFF = 'WAREHOUSE_STAFF';

    public const ORDER_STAFF = 'ORDER_STAFF';

    public const ACCOUNTANT = 'ACCOUNTANT';

    public const VIEWER = 'VIEWER';

    protected $fillable = ['key', 'name', 'is_system', 'rank'];

    protected $hidden = ['id', 'tenant_id', 'pivot'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        return $this->permissions->pluck('key')->sort()->values()->all();
    }

    public function isOwner(): bool
    {
        return $this->is_system && $this->key === self::OWNER;
    }

    public function scopeVisibleToCurrentTenant(Builder $query): Builder
    {
        $tenantId = app(TenantContext::class)->id();

        return $query->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    public static function system(string $key): self
    {
        return static::query()->whereNull('tenant_id')->where('key', $key)->firstOrFail();
    }
}
