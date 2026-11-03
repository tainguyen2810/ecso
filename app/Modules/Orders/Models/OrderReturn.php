<?php

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Goods coming back from a customer (ORDER-LIFECYCLE §4–5). Stock is added back only at inspection, for the
 * quantity inspected as sellable. A refund alone never adds stock.
 *
 * @property Carbon|null $received_at
 * @property Carbon|null $inspected_at
 * @property Carbon|null $closed_at
 */
class OrderReturn extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const TYPE_RETURN = 'return';

    public const TYPE_REFUND_ONLY = 'refund_only';

    public const TYPE_CANCEL_AFTER_SHIP = 'cancel_after_ship';

    public const TYPE_FAILED_DELIVERY = 'failed_delivery';

    public const TYPES = [self::TYPE_RETURN, self::TYPE_REFUND_ONLY, self::TYPE_CANCEL_AFTER_SHIP, self::TYPE_FAILED_DELIVERY];

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const IN_TRANSIT = 'in_transit';

    public const RECEIVED = 'received';

    public const INSPECTED = 'inspected';

    public const CLOSED = 'closed';

    public const REJECTED = 'rejected';

    /** Status => statuses it can move to by a user action. */
    public const FLOW = [
        self::REQUESTED => [self::APPROVED, self::REJECTED],
        self::APPROVED => [self::IN_TRANSIT, self::RECEIVED, self::CLOSED],
        self::IN_TRANSIT => [self::RECEIVED],
        self::RECEIVED => [self::INSPECTED],
        self::INSPECTED => [self::CLOSED],
        self::CLOSED => [],
        self::REJECTED => [],
    ];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'order_id', 'warehouse_id'];

    protected function casts(): array
    {
        return ['refund_amount' => 'decimal:2', 'exchange_credit' => 'decimal:2', 'received_at' => 'datetime', 'inspected_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function expectsGoods(): bool
    {
        return $this->type !== self::TYPE_REFUND_ONLY;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<OrderReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderReturnLine::class)->orderBy('id');
    }

    /**
     * The new order the buyer took in exchange, paid (partly) by this return's credit.
     *
     * @return BelongsTo<Order, $this>
     */
    public function exchangeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'exchange_order_id');
    }

    /**
     * What the returned goods are worth to the buyer: the refund agreed on the return, or else what was paid for the
     * returned quantities (each line's discount spread over its units).
     */
    public function goodsValue(): string
    {
        if ($this->refund_amount !== null) {
            return (string) $this->refund_amount;
        }

        return $this->lines()->with('item')->get()->reduce(function (string $sum, OrderReturnLine $line) {
            $item = $line->item;
            if ($item === null || $item->quantity === 0) {
                return $sum;
            }
            $paid = bcsub(bcmul((string) $item->unit_price, (string) $item->quantity, 2), (string) $item->discount, 2);

            return bcadd($sum, bcdiv(bcmul($paid, (string) $line->quantity, 4), (string) $item->quantity, 2), 2);
        }, '0.00');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
