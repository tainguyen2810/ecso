<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_LIMIT = 'limit';

    protected $fillable = ['key', 'type', 'name'];
}
