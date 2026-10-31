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

    protected $fillable = ['product_variant_id', 'mode', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'applied_delta' => 'integer', 'on_hand_before' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
