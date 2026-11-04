<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated note on a customer, kept as a timeline: a plain note, or a contact (call, message, meeting, complaint).
 */
class CustomerNote extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const KINDS = ['note', 'call', 'message', 'meeting', 'complaint'];

    protected $fillable = ['body', 'kind'];

    protected $hidden = ['id', 'tenant_id', 'customer_id', 'created_by'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
