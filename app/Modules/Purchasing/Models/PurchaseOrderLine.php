<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_qty' => 'integer', 'returned_qty' => 'integer', 'unit_cost' => 'decimal:4', 'tax_rate' => 'decimal:2'];
    }

    public function remaining(): int
    {
        return max(0, $this->quantity - $this->received_qty);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
