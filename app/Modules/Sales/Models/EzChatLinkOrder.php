<?php

namespace App\Modules\Sales\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The order EzChat's order `code` (closed in chat) became here: an order sent twice is made once. */
class EzChatLinkOrder extends Model
{
    use BelongsToTenant;

    protected $table = 'ezchat_link_orders';

    protected $fillable = ['code', 'order_id'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
