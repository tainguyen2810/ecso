<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A promotion of the shop's own channels and POS (C4). Without a code it applies by itself to every order that
 * qualifies; with one, only when the code is typed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $name
 * @property string $type
 * @property string|null $code
 * @property string $discount_kind
 * @property string $discount_value
 * @property string|null $max_discount
 * @property string $min_subtotal
 * @property array<int, array{min_subtotal: string|int|float, value: string|int|float}>|null $tiers
 * @property int|null $buy_qty
 * @property int|null $gift_variant_id
 * @property int|null $gift_qty
 * @property array<int, int>|null $channel_ids
 * @property array<int, int>|null $variant_ids
 * @property array<int, int>|null $category_ids
 * @property array<int, int>|null $customer_group_ids
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $usage_limit
 * @property int|null $per_customer_limit
 * @property string $status
 * @property-read ProductVariant|null $giftVariant
 */
class Promotion extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const ORDER = 'order';

    public const PRODUCT = 'product';

    public const GIFT = 'gift';

    public const TYPES = [self::ORDER, self::PRODUCT, self::GIFT];

    public const KINDS = ['percent', 'amount', 'fixed_price'];

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    protected $fillable = [
        'name', 'type', 'code', 'discount_kind', 'discount_value', 'max_discount', 'min_subtotal', 'tiers', 'buy_qty', 'gift_variant_id', 'gift_qty',
        'channel_ids', 'variant_ids', 'category_ids', 'customer_group_ids', 'starts_at', 'ends_at', 'usage_limit', 'per_customer_limit', 'status',
    ];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2', 'max_discount' => 'decimal:2', 'min_subtotal' => 'decimal:2', 'tiers' => 'array',
            'channel_ids' => 'array', 'variant_ids' => 'array', 'category_ids' => 'array', 'customer_group_ids' => 'array',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'buy_qty' => 'integer', 'gift_qty' => 'integer',
            'usage_limit' => 'integer', 'per_customer_limit' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function giftVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'gift_variant_id');
    }

    /** running | scheduled | ended | paused */
    public function state(?Carbon $at = null): string
    {
        $at ??= now();
        if ($this->status === self::PAUSED) {
            return 'paused';
        }
        if ($this->starts_at !== null && $this->starts_at->gt($at)) {
            return 'scheduled';
        }
        if ($this->ends_at !== null && $this->ends_at->lt($at)) {
            return 'ended';
        }

        return 'running';
    }
}
