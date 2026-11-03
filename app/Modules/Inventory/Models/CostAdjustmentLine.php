<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Child of a tenant-scoped CostAdjustment; always reached through it. */
class CostAdjustmentLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_variant_id', 'old_cost', 'new_cost', 'on_hand'];

    protected function casts(): array
    {
        return ['old_cost' => 'decimal:4', 'new_cost' => 'decimal:4', 'on_hand' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
