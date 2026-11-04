<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A follow-up on a customer: call back, send a quote, remind about a payment. Due at a time, for a staff member.
 *
 * @property Carbon $due_at
 * @property Carbon|null $done_at
 */
class CustomerTask extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['title', 'due_at'];

    protected $hidden = ['id', 'tenant_id', 'customer_id', 'assigned_user_id', 'done_by', 'created_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
