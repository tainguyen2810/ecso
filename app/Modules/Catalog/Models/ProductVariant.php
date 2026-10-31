<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable SKU. Stock is tracked per variant and warehouse by the Inventory module.
 * cost_price (weighted average) is only written by InventoryService.
 *
 * @property-read int|string|null $on_hand  stock join in listings
 * @property-read int|string|null $reserved  stock join in listings
 * @property-read int|string|null $safety_stock  stock join in listings
 */
class ProductVariant extends Model
{
    use BelongsToTenant, HasPublicUlid, SoftDeletes;

    protected $fillable = ['sku', 'barcode', 'name', 'options', 'ref_price', 'weight_gram', 'status'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'cost_price' => 'decimal:4',
            'ref_price' => 'decimal:2',
            'weight_gram' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function displayName(): string
    {
        return trim($this->product?->name.($this->name ? ' - '.$this->name : ''));
    }

    /**
     * SKUs are compared case-insensitively and without surrounding whitespace (MySQL collation is case-insensitive too).
     */
    public static function normalizeSku(string $sku): string
    {
        return trim($sku);
    }
}
