<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Events\TenantCreated;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, timezone?: string, currency?: string}  $data
     */
    public function create(User $owner, array $data): Tenant
    {
        return DB::transaction(function () use ($owner, $data) {
            $tenant = new Tenant($data);
            $tenant->owner_user_id = $owner->id;
            $tenant->slug = $this->uniqueSlug($data['name']);
            $tenant->status = Tenant::STATUS_ACTIVE;
            $tenant->save();

            return TenantContext::run($tenant, function () use ($tenant, $owner) {
                $membership = TenantUser::create([
                    'user_id' => $owner->id,
                    'role_id' => Role::system(Role::OWNER)->id,
                    'status' => TenantUser::STATUS_ACTIVE,
                    'joined_at' => now(),
                ]);

                Subscription::create([
                    'plan_id' => Plan::query()->where('key', Plan::FREE)->value('id'),
                    'status' => Subscription::STATUS_ACTIVE,
                    'current_period_start' => now(),
                ]);

                $this->audit->record('tenant.created', $tenant, ['name' => $tenant->name], $tenant->id, $owner->id);
                $this->audit->record('member.joined', $membership, ['role' => Role::OWNER], $tenant->id, $owner->id);

                TenantCreated::dispatch($tenant);

                return $tenant;
            });
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
