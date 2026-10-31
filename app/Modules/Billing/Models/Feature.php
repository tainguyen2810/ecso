<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property-read Pivot $pivot  set when loaded through Plan::features() (enabled, limit_value)
 */
class Feature extends Model
{
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_LIMIT = 'limit';

    protected $fillable = ['key', 'type', 'name'];
}
