<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read int|string|null $sku_count  aggregate in the warehouse list
 * @property-read int|string|null $total_on_hand  aggregate in the warehouse list
 */
class Warehouse extends Model
{
    use BelongsToTenant, HasPublicUlid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['code', 'name', 'address', 'status'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'allow_negative' => 'boolean'];
    }

    /** @return HasMany<StockBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
