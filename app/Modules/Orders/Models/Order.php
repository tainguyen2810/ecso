<?php

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Casts\MicroTimestamp;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $placed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $ship_by_at
 * @property Carbon|null $external_updated_at
 * @property Carbon|null $handed_over_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $packing_slip_printed_at
 * @property Carbon|null $deliver_on
 * @property array<int, string>|null $tags
 */
class Order extends Model
{
    use BelongsToTenant, HasPublicUlid;

    /** Reference type of stock reservations held for order items. */
    public const STOCK_REFERENCE = 'order_item';

    /** `marketplace` value of orders created in ECSO (own sales channels), as opposed to marketplace orders. */
    public const SOURCE_MANUAL = 'manual';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'marketplace_account_id', 'sales_channel_id', 'customer_id', 'warehouse_id', 'assigned_to', 'created_by', 'raw'];

    protected function casts(): array
    {
        return [
            'buyer_name' => 'encrypted',
            'shipping_address' => 'encrypted',
            'buyer_phone' => 'encrypted',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'is_cod' => 'boolean',
            'has_shortage' => 'boolean',
            'has_unmapped' => 'boolean',
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'ship_by_at' => 'datetime',
            'external_updated_at' => MicroTimestamp::class,
            'handed_over_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'packing_slip_printed_at' => 'datetime',
            'raw' => 'array',
            'tags' => 'array',
            'deliver_on' => 'date',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    public function isManual(): bool
    {
        return $this->marketplace === self::SOURCE_MANUAL;
    }

    /** Amount still to collect from the buyer (manual orders). */
    public function balanceDue(): string
    {
        return bcsub((string) $this->total, (string) $this->paid_amount, 2);
    }

    /** @return BelongsTo<SalesChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OrderPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** @return HasMany<OrderNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->orderBy('id');
    }

    /** @return HasMany<OrderReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class)->orderBy('id');
    }
}
