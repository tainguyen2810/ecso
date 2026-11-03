<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received from (payment) or given back to (refund) the buyer of a manual order. Never edited: a mistake is
 * voided (kept for the audit trail) and recorded again.
 *
 * @property Carbon $paid_at
 * @property Carbon|null $voided_at
 */
class OrderPayment extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    public const METHODS = ['cash', 'bank_transfer', 'card', 'ewallet', 'cod', 'other'];

    /** Set-off between a return and its exchange order (ExchangeService); never chosen by hand. */
    public const EXCHANGE = 'exchange';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'order_id', 'customer_id', 'created_by', 'voided_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
