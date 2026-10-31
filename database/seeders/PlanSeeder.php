<?php

namespace Database\Seeders;

use App\Modules\Billing\Models\Feature;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Support\PlanCatalog;
use Illuminate\Database\Seeder;

/**
 * Creates missing plans/features only; never overwrites values an operator changed in the database.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PlanCatalog::FEATURES as $key => $feature) {
            Feature::query()->firstOrCreate(['key' => $key], $feature);
        }

        $features = Feature::query()->pluck('id', 'key');

        foreach (PlanCatalog::PLANS as $key => $definition) {
            $plan = Plan::query()->firstOrCreate(['key' => $key], collect($definition)->except('features')->all());

            foreach ($definition['features'] as $featureKey => $value) {
                $exists = $plan->features()->where('features.id', $features[$featureKey])->exists();
                if (! $exists) {
                    $plan->features()->attach($features[$featureKey], [
                        'enabled' => $value !== false,
                        'limit_value' => is_int($value) ? $value : null,
                    ]);
                }
            }
        }
    }
}
