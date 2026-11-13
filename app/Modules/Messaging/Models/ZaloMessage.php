<?php

namespace App\Modules\Messaging\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One ZNS message EzSell sent (or tried to) for an order event. The phone is kept masked only.
 *
 * @property int $id
 * @property int|null $order_id
 * @property string $event
 * @property string $template_id
 * @property string|null $template_name
 * @property string $phone_masked
 * @property string|null $buyer_name
 * @property string $status
 * @property string|null $error
 * @property string|null $msg_id
 * @property Carbon|null $created_at
 * @property-read Order|null $order
 */
class ZaloMessage extends Model
{
    use BelongsToTenant;

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
