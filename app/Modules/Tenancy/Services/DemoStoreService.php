<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Jobs\PopulateDemoStore;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A2: "Cửa hàng mẫu", a business of its own filled with fake data so a new user can click through everything
 * without touching the real store (stock, cash book and debts are ledgers that cannot be edited back). One per
 * user, on Pro without a billing period, never connected to a real marketplace or service, deleted as a whole after 30 days.
 */
class DemoStoreService
{
    public const NAME = 'Cửa hàng mẫu';

    public function __construct(private readonly TenantService $tenants, private readonly AuditLogger $audit) {}

    /** The user's sample store, made now if they have none. The data is filled by a queued job. */
    public function createFor(User $user): Tenant
    {
        $existing = $this->of($user);
        if ($existing) {
            return $existing;
        }

        $tenant = $this->tenants->create($user, ['name' => self::NAME]);
        $tenant->forceFill([
            'is_demo' => true,
            'demo_status' => 'preparing',
            'demo_expires_at' => now()->addDays(Tenant::DEMO_DAYS),
            'setup_completed_at' => now(),
        ])->save();
        // Pro features (advanced reports, a second warehouse) without a billing period: billing never touches it.
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update([
            'plan_id' => Plan::query()->where('key', 'pro')->value('id'),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => null,
        ]);

        PopulateDemoStore::dispatch($tenant->id, $user->id);

        return $tenant->refresh();
    }

    public function of(User $user): ?Tenant
    {
        return Tenant::query()->where('owner_user_id', $user->id)->where('is_demo', true)->first();
    }

    /**
     * Deletes a sample store and everything in it. Refuses any business that is not a sample store: real books are
     * never deleted this way.
     */
    public function purge(Tenant $tenant, ?int $actorId = null): void
    {
        $tenant->refresh();
        if (! $tenant->is_demo) {
            throw new RuntimeException('Chỉ xóa được cửa hàng mẫu.');
        }

        $id = $tenant->id;
        $schema = DB::getDatabaseName();
        $tenantTables = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $schema)->where('COLUMN_NAME', 'tenant_id')
            ->where('TABLE_NAME', '!=', 'tenants')->pluck('TABLE_NAME')->map(fn ($t) => (string) $t)->all();
        // Lines that hang off a tenant table without a tenant_id of their own (e.g. stock_receipt_lines).
        $children = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', $schema)
            ->whereIn('REFERENCED_TABLE_NAME', $tenantTables)->whereNotIn('TABLE_NAME', $tenantTables)->where('TABLE_NAME', '!=', 'tenants')
            ->get(['TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME']);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($id, $tenantTables, $children) {
                foreach ($children as $fk) {
                    DB::table((string) $fk->TABLE_NAME)->whereIn((string) $fk->COLUMN_NAME,
                        DB::table((string) $fk->REFERENCED_TABLE_NAME)->where('tenant_id', $id)->select((string) $fk->REFERENCED_COLUMN_NAME))->delete();
                }
                foreach ($tenantTables as $table) {
                    DB::table($table)->where('tenant_id', $id)->delete();
                }
                DB::table('tenants')->where('id', $id)->delete();
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $context = app(TenantContext::class);
        if ($context->has() && $context->id() === $id) {
            $context->clear();
        }
        foreach (['public', 'local'] as $disk) {
            Storage::disk($disk)->deleteDirectory("tenants/{$tenant->ulid}");
        }
        $this->audit->record('tenant.demo_deleted', null, ['tenant' => $tenant->ulid, 'name' => $tenant->name], null, $actorId);
    }

    /** Sample stores past their 30 days. */
    public function pruneExpired(): int
    {
        $count = 0;
        Tenant::query()->where('is_demo', true)->where('demo_expires_at', '<', now())->each(function (Tenant $tenant) use (&$count) {
            $this->purge($tenant);
            $count++;
        });

        return $count;
    }
}
