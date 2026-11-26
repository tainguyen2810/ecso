<?php

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $subscription Thuê bao line: months, start, label, subscription_id
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    /** mapping_status of a service line on an own-channel order: no SKU, never reserves or moves stock. */
    public const SERVICE = 'service';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'refunded_quantity' => 'integer', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'cost_snapshot' => 'decimal:4', 'subscription' => 'array'];
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

    /**
     * The SKU of a service or subscription line (no stock): kept apart from product_variant_id.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function serviceVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'service_variant_id')->withTrashed();
    }

    /** @return BelongsTo<ListingVariant, $this> */
    public function listingVariant(): BelongsTo
    {
        return $this->belongsTo(ListingVariant::class);
    }
}
