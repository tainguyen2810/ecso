<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One component SKU of a combo SKU, with the quantity used per combo. */
class ProductComponent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['component_variant_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id')->withTrashed();
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
