<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Subscriptions\SubscriptionService;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Thuê bao: one service a customer uses for a time (hosting, bảo trì…). Its dates are those of its active periods,
 * one period per order line that sold or renewed it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $code
 * @property int $customer_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string|null $label
 * @property string $status
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $assigned_to
 * @property bool $remind
 * @property array<string, mixed>|null $reminded
 * @property int|null $renewal_order_id
 * @property Carbon|null $paused_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $note
 * @property-read Customer|null $customer
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class CustomerSubscription extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'tenant_id', 'ulid'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'remind' => 'boolean',
            'reminded' => 'array',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Order, $this> */
    public function renewalOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'renewal_order_id');
    }

    /** @return HasMany<CustomerSubscriptionPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(CustomerSubscriptionPeriod::class);
    }

    /** @return HasMany<CustomerSubscriptionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CustomerSubscriptionEvent::class);
    }

    /** Days from today to the last day of use (negative once it has ended); null before a first period is confirmed. */
    public function daysLeft(?Carbon $today = null): ?int
    {
        if ($this->ends_on === null) {
            return null;
        }
        $today ??= SubscriptionService::today();

        return (int) $today->copy()->startOfDay()->diffInDays($this->ends_on->copy()->startOfDay(), false);
    }

    /**
     * What a seller reads: pending (sold, order not confirmed yet), active, expiring (≤ 30 days), overdue (ended,
     * still in grace), expired, paused, cancelled.
     */
    public function state(?Carbon $today = null): string
    {
        if ($this->status === self::CANCELLED) {
            return 'cancelled';
        }
        if ($this->status === self::PAUSED) {
            return 'paused';
        }
        $left = $this->daysLeft($today);
        if ($left === null) {
            return 'pending';
        }
        if ($left < 0) {
            $grace = $this->relationLoaded('product') ? $this->product->subscriptionRules()['grace_days'] : 0;

            return -$left <= $grace ? 'overdue' : 'expired';
        }

        return $left <= 30 ? 'expiring' : 'active';
    }
}
