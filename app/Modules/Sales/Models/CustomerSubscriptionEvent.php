<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Nhật ký of a subscription: created, renewed, reminded, renewal order, paused, resumed, cancelled, changed.
 *
 * @property int $id
 * @property string $kind
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class CustomerSubscriptionEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['customer_subscription_id', 'kind', 'note', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
