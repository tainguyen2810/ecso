<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\UsageCounter;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Current usage of limited features. Users, shops and warehouses are counted live; orders per month are metered
 * in usage_counters (incremented when a new order is first imported).
 */
class UsageService
{
    /** Limits enforced when creating the resource (and checked before a downgrade). */
    public const HARD_LIMITS = ['limit.users', 'limit.shops', 'limit.warehouses'];

    public const ORDERS = 'limit.orders_per_month';

    public function current(string $feature): int
    {
        return match ($feature) {
            // Active members plus pending invitations, as enforced when inviting (Phase 1).
            'limit.users' => DB::table('tenant_users')->where('tenant_id', $this->tenantId())->count()
                + DB::table('tenant_invitations')->where('tenant_id', $this->tenantId())->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'limit.shops' => DB::table('marketplace_accounts')->where('tenant_id', $this->tenantId())->where('status', '!=', 'revoked')->count(),
            'limit.warehouses' => DB::table('warehouses')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->count(),
            self::ORDERS => (int) UsageCounter::query()->where('metric', self::ORDERS)->where('period', now()->format('Y-m'))->value('value'),
            default => 0,
        };
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return collect([...self::HARD_LIMITS, self::ORDERS])->mapWithKeys(fn ($f) => [$f => $this->current($f)])->all();
    }

    /** Atomic increment, safe under concurrent workers. The tenant id comes from server-side data (the order). */
    public static function increment(int $tenantId, string $metric, string $period, int $by = 1): void
    {
        DB::statement(
            'INSERT INTO usage_counters (tenant_id, metric, period, value, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = value + VALUES(value), updated_at = NOW()',
            [$tenantId, $metric, $period, $by],
        );
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->id();
    }
}
