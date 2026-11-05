<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a SKU's sellable stock one shop publishes: the shop default (product_variant_id NULL) or a SKU
 * override. See InventorySyncPlanner and AllocationCalculator::publish().
 *
 * @property int $marketplace_account_id
 * @property int|null $product_variant_id
 * @property string $mode
 * @property int|null $value
 * @property int $buffer_qty
 * @property int|null $max_qty
 */
class MarketplaceAllocation extends Model
{
    use BelongsToTenant;

    public const SHOP = 'shop';

    protected $fillable = ['marketplace_account_id', 'product_variant_id', 'scope', 'mode', 'value', 'buffer_qty', 'max_qty'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'buffer_qty' => 'integer', 'max_qty' => 'integer'];
    }

    public static function scopeKey(?int $variantId): string
    {
        return $variantId === null ? self::SHOP : 'v'.$variantId;
    }

    /** @return array{mode: string, value: int|null, buffer_qty: int, max_qty: int|null} */
    public function rule(): array
    {
        return ['mode' => $this->mode, 'value' => $this->value, 'buffer_qty' => (int) $this->buffer_qty, 'max_qty' => $this->max_qty];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
