<?php

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Exceptions\PlanLimitExceeded;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Effective features of a tenant = plan features overridden by tenant_feature_overrides.
 */
final class Entitlements
{
    /**
     * @param  array<string, array{enabled: bool, limit: int|null}>  $features
     */
    private function __construct(private readonly array $features) {}

    public static function for(Tenant $tenant): self
    {
        $planFeatures = DB::table('subscriptions')
            ->join('plan_features', 'plan_features.plan_id', '=', 'subscriptions.plan_id')
            ->join('features', 'features.id', '=', 'plan_features.feature_id')
            ->where('subscriptions.tenant_id', $tenant->id)
            ->get(['features.key', 'plan_features.enabled', 'plan_features.limit_value']);

        $overrides = DB::table('tenant_feature_overrides')
            ->join('features', 'features.id', '=', 'tenant_feature_overrides.feature_id')
            ->where('tenant_feature_overrides.tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['features.key', 'tenant_feature_overrides.enabled', 'tenant_feature_overrides.limit_value']);

        $features = [];
        foreach ([...$planFeatures, ...$overrides] as $row) {
            $features[$row->key] = [
                'enabled' => (bool) $row->enabled,
                'limit' => $row->limit_value === null ? null : (int) $row->limit_value,
            ];
        }

        return new self($features);
    }

    public function allows(string $feature): bool
    {
        return $this->features[$feature]['enabled'] ?? false;
    }

    /**
     * null = unlimited. A feature missing from the plan counts as limit 0 (fail closed).
     */
    public function limit(string $feature): ?int
    {
        if (! isset($this->features[$feature]) || ! $this->features[$feature]['enabled']) {
            return 0;
        }

        return $this->features[$feature]['limit'];
    }

    public function assertWithin(string $feature, int $currentUsage, int $adding = 1): void
    {
        $limit = $this->limit($feature);

        if ($limit !== null && $currentUsage + $adding > $limit) {
            throw new PlanLimitExceeded($feature, $limit);
        }
    }

    /**
     * @return array<string, array{enabled: bool, limit: int|null}>
     */
    public function toArray(): array
    {
        return $this->features;
    }
}
