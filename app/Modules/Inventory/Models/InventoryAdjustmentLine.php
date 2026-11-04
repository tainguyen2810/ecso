<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Child of a tenant-scoped InventoryAdjustment; always reached through it. */
class InventoryAdjustmentLine extends Model
{
    public const MODE_DELTA = 'delta';

    public const MODE_COUNT = 'count';

    public $timestamps = false;

    protected $fillable = ['product_variant_id', 'mode', 'quantity', 'system_qty', 'reason', 'note'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'system_qty' => 'integer', 'applied_delta' => 'integer', 'on_hand_before' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
