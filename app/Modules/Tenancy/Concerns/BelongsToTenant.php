<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every business model with a tenant_id column must use this trait (enforced by tests/Arch).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            // Always taken from the server-side context; any client value is overwritten.
            $model->tenant_id = app(TenantContext::class)->id();
        });
    }

    public function initializeBelongsToTenant(): void
    {
        $this->guarded = array_values(array_unique([...$this->guarded, 'tenant_id']));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
