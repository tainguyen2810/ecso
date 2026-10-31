<?php

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Fails closed: without a tenant context, TenantContext::id() throws instead of returning every tenant's rows.
        $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
    }
}
