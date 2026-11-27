<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period of a subscription, sold by one order line. Pending while the order waits for confirmation, active once
 * confirmed, void when the order is cancelled.
 *
 * @property int $id
 * @property int $customer_subscription_id
 * @property int $order_id
 * @property int $line_no
 * @property int|null $product_variant_id
 * @property int $months
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $amount
 * @property string $status
 * @property-read Order|null $order
 * @property-read ProductVariant|null $variant
 */
class CustomerSubscriptionPeriod extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const VOID = 'void';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'amount' => 'decimal:2', 'months' => 'integer', 'line_no' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<CustomerSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'customer_subscription_id');
    }
}
