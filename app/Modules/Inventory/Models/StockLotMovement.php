<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** The lot part of a stock movement. Immutable, like stock_movements. */
class StockLotMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['on_hand_delta' => 'integer', 'reserved_delta' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lot movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Lot movements are immutable.'));
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }
}
