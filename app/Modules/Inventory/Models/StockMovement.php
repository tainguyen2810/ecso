<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable stock ledger row. Never updated or deleted.
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const OPENING = 'OPENING';

    public const RECEIPT = 'RECEIPT';

    public const RESERVE = 'RESERVE';

    public const RELEASE = 'RELEASE';

    public const SHIP = 'SHIP';

    public const SHIP_UNRESERVED = 'SHIP_UNRESERVED';

    public const RETURN_RESTOCK = 'RETURN_RESTOCK';

    public const DAMAGE_OUT = 'DAMAGE_OUT';

    public const ADJUSTMENT_IN = 'ADJUSTMENT_IN';

    public const ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';

    public const TRANSFER_OUT = 'TRANSFER_OUT';

    public const TRANSFER_IN = 'TRANSFER_IN';

    public const SUPPLIER_RETURN = 'SUPPLIER_RETURN';

    public const RESERVATION_REALLOCATE = 'RESERVATION_REALLOCATE';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Stock movements are immutable.'));
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

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
