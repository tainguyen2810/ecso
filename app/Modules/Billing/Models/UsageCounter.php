<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Monthly usage of a metered feature (for example orders per month). */
class UsageCounter extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
