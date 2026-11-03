<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated note on a customer (call, complaint, preference), kept as a timeline.
 */
class CustomerNote extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['body'];

    protected $hidden = ['id', 'tenant_id', 'customer_id', 'created_by'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
