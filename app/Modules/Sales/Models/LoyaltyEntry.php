<?php

namespace App\Modules\Sales\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change of a customer's points (C5). Positive adds, negative takes away; "tier" rows carry 0 points and only
 * record a tier change for the history.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int|null $order_id
 * @property string $kind
 * @property int $points
 * @property string|null $multiplier
 * @property string|null $note
 * @property Carbon|null $expires_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Order|null $order
 */
class LoyaltyEntry extends Model
{
    use BelongsToTenant;

    public const EARN = 'earn';

    public const RETURN = 'return';

    public const REDEEM = 'redeem';

    public const REDEEM_BACK = 'redeem_back';

    public const ADJUST = 'adjust';

    public const EXPIRE = 'expire';

    public const TIER = 'tier';

    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'order_id', 'kind', 'points', 'multiplier', 'note', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'multiplier' => 'decimal:2', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
