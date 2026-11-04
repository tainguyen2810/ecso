<?php

namespace App\Modules\Orders\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One refund made on the store (WooCommerce), kept once per store refund id. Goods that left the warehouse come
 * back only through the return it opened (order_return_id), when they are received and inspected.
 *
 * @property array<int, array{order_item_id: int|null, external_item_id: string, quantity: int, amount: string}>|null $lines
 * @property Carbon|null $refunded_at
 */
class OrderRefund extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'order_id', 'order_return_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'lines' => 'array', 'refunded_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderReturn, $this> */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }
}
