<?php

namespace App\Modules\Sales\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A group of possible duplicate cards that someone checked and marked "Không phải trùng" (not the same buyer).
 *
 * @property string $group_key
 * @property string $reason
 * @property string|null $value
 * @property int|null $created_by
 */
class CustomerDuplicateDismissal extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['group_key', 'reason', 'value', 'created_by'];
}
