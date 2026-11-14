<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Feature;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A1: plans are set up by the platform admin after install (prices, trial, limits, what the plans list shows).
 * A new price applies from the next renewal: issued invoices keep their amount. A plan in use cannot be deleted,
 * only hidden; the free plan (given at sign-up and after an unpaid period) can never be deleted.
 */
class PlanService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Plan
    {
        return DB::transaction(function () use ($data) {
            $plan = Plan::query()->create([
                ...$this->attributes($data),
                'key' => $data['key'],
                'sort' => (int) Plan::query()->max('sort') + 1,
            ]);
            $this->saveFeatures($plan, $data['features'] ?? []);
            $this->audit->record('admin.plan_created', $plan, ['key' => $plan->key]);

            return $plan;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Plan $plan, array $data): Plan
    {
        return DB::transaction(function () use ($plan, $data) {
            $before = $this->snapshot($plan);
            $plan->fill($this->attributes($data))->save();
            if (array_key_exists('features', $data)) {
                $this->saveFeatures($plan, $data['features']);
            }
            $after = $this->snapshot($plan->refresh());
            $changed = array_filter($after, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                $this->audit->record('admin.plan_updated', $plan, ['before' => array_intersect_key($before, $changed), 'after' => $changed]);
            }

            return $plan;
        });
    }

    public function delete(Plan $plan): void
    {
        if ($plan->key === Plan::FREE) {
            throw ValidationException::withMessages(['plan' => 'Gói miễn phí là gói mặc định khi đăng ký, không xóa được.']);
        }
        if ($this->inUse($plan)) {
            throw ValidationException::withMessages(['plan' => 'Gói đã có khách dùng hoặc có hóa đơn: chỉ ẩn được, không xóa được.']);
        }
        DB::transaction(function () use ($plan) {
            $plan->features()->detach();
            $plan->delete();
            $this->audit->record('admin.plan_deleted', null, ['key' => $plan->key]);
        });
    }

    /** @param  list<string>  $keys  every plan key, in the new order */
    public function reorder(array $keys): void
    {
        $all = Plan::query()->pluck('key')->all();
        if (count($keys) !== count($all) || array_diff($all, $keys) !== []) {
            throw ValidationException::withMessages(['keys' => 'Danh sách gói không khớp.']);
        }
        DB::transaction(function () use ($keys) {
            foreach ($keys as $i => $key) {
                Plan::query()->where('key', $key)->update(['sort' => $i + 1]);
            }
        });
    }

    public function inUse(Plan $plan): bool
    {
        return Subscription::withoutGlobalScopes()->where(fn ($q) => $q->where('plan_id', $plan->id)->orWhere('pending_plan_id', $plan->id))->exists()
            || Invoice::withoutGlobalScopes()->where('plan_id', $plan->id)->exists();
    }

    /**
     * Prices, limits and display text of a plan, as the plans list (in the app and on the website) shows them.
     *
     * @return array<string, mixed>
     */
    public function present(Plan $plan): array
    {
        $plan->loadMissing('features');

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'badge' => $plan->badge,
            'highlights' => $plan->highlights ?? [],
            'price_monthly' => (float) $plan->price_monthly,
            'price_yearly' => (float) $plan->price_yearly,
            'trial_days' => $plan->trial_days,
            'is_public' => $plan->is_public,
            'features' => $plan->features->mapWithKeys(fn ($f) => [$f->key => [
                'name' => $f->name,
                'type' => $f->type,
                'enabled' => (bool) $f->pivot->getAttribute('enabled'),
                'limit' => $f->pivot->getAttribute('limit_value') === null ? null : (int) $f->pivot->getAttribute('limit_value'),
            ]])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'price_monthly', 'price_yearly', 'trial_days', 'is_public']));
        foreach (['description', 'badge'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = trim((string) $data[$key]) ?: null;
            }
        }
        if (array_key_exists('highlights', $data)) {
            $lines = array_map(fn ($line) => trim((string) $line), (array) $data['highlights']);
            $attributes['highlights'] = array_values(array_filter($lines, fn ($line) => $line !== ''));
        }

        return $attributes;
    }

    /**
     * Limit features: enabled with a number, or with null for unlimited. Switch features: on or off. A feature left out
     * keeps its current value; one the plan never had is created off, so a new feature fails closed.
     *
     * @param  array<string, array{enabled?: bool, limit?: int|null}>  $features
     */
    private function saveFeatures(Plan $plan, array $features): void
    {
        foreach (Feature::query()->get() as $feature) {
            $current = $plan->features()->where('features.id', $feature->id)->first();
            $given = $features[$feature->key] ?? null;
            if ($given === null && $current) {
                continue;
            }
            $enabled = (bool) ($given['enabled'] ?? false);
            $limit = $feature->type === Feature::TYPE_LIMIT && $enabled && isset($given['limit']) ? (int) $given['limit'] : null;
            $pivot = ['enabled' => $enabled, 'limit_value' => $limit];
            $current ? $plan->features()->updateExistingPivot($feature->id, $pivot) : $plan->features()->attach($feature->id, $pivot);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Plan $plan): array
    {
        $plan->load('features');
        $row = $this->present($plan);
        $row['features'] = array_map(fn ($f) => ['enabled' => $f['enabled'], 'limit' => $f['limit']], $row['features']);

        return $row;
    }
}
