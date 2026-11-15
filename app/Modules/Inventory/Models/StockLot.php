<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Quantity of one lot of a SKU in one warehouse. Written only by LotLedger, inside InventoryService operations
 * (tests/Arch). lot_no '' is "Chưa rõ lô".
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $product_variant_id
 * @property string $lot_no
 * @property Carbon|null $mfg_date
 * @property Carbon|null $expiry_date
 * @property int $on_hand
 * @property int $reserved
 * @property Carbon|null $first_received_at
 */
class StockLot extends Model
{
    use BelongsToTenant;

    public const UNKNOWN = '';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'first_received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function free(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    public function label(): string
    {
        return $this->lot_no === self::UNKNOWN ? 'Chưa rõ lô' : $this->lot_no;
    }
}
