<?php

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Models\MarketplaceAccount;
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
 */
class Order extends Model
{
    use BelongsToTenant, HasPublicUlid;

    /** Reference type of stock reservations held for order items. */
    public const STOCK_REFERENCE = 'order_item';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'marketplace_account_id', 'warehouse_id', 'assigned_to', 'raw'];

    protected function casts(): array
    {
        return [
            'buyer_name' => 'encrypted',
            'shipping_address' => 'encrypted',
            'is_cod' => 'boolean',
            'has_shortage' => 'boolean',
            'has_unmapped' => 'boolean',
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'discount' => 'decimal:2',
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
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
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
