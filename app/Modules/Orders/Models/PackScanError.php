<?php

namespace App\Modules\Orders\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A wrong or extra scan on the packing screen (never counted as packed). */
class PackScanError extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const KINDS = ['wrong', 'extra'];

    protected $fillable = ['order_id', 'user_id', 'code', 'kind'];
}
