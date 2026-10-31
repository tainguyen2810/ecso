<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    public const FREE = 'free';

    protected $fillable = ['key', 'name', 'price_monthly', 'price_yearly', 'trial_days', 'is_public', 'sort'];

    protected $hidden = ['id', 'pivot'];

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features')->withPivot(['enabled', 'limit_value']);
    }
}
