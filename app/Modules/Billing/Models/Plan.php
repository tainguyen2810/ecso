<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $price_monthly
 * @property string $price_yearly
 * @property int $trial_days
 * @property bool $is_public
 */
class Plan extends Model
{
    public const FREE = 'free';

    protected $fillable = ['key', 'name', 'price_monthly', 'price_yearly', 'trial_days', 'is_public', 'sort'];

    protected $hidden = ['id', 'pivot'];

    protected function casts(): array
    {
        return ['price_monthly' => 'decimal:2', 'price_yearly' => 'decimal:2', 'trial_days' => 'integer', 'is_public' => 'boolean'];
    }

    public function price(string $cycle): float
    {
        return (float) ($cycle === 'yearly' ? $this->price_yearly : $this->price_monthly);
    }

    /** Price per month, used for MRR (a yearly price counts as 1/12 per month). */
    public function monthlyValue(string $cycle): float
    {
        return $cycle === 'yearly' ? round($this->price('yearly') / 12, 2) : $this->price('monthly');
    }

    public function isFree(): bool
    {
        return $this->price('monthly') <= 0 && $this->price('yearly') <= 0;
    }

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features')->withPivot(['enabled', 'limit_value']);
    }
}
