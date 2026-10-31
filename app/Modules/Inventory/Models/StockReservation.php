<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const ACTIVE = 'active';

    public const CONSUMED = 'consumed';

    public const RELEASED = 'released';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'shortage' => 'integer',
            'released_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
