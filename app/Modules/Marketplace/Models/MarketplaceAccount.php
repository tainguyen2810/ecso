<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A connected marketplace shop.
 *
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $circuit_open_until
 * @property-read int|string|null $listings_total  aggregates of the shop list
 * @property-read int|string|null $listings_mapped
 * @property-read int|string|null $listings_suggested
 * @property-read int|string|null $push_errors
 * @property-read int|string|null $pending_push
 */
class MarketplaceAccount extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const CONNECTED = 'connected';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const ERROR = 'error';

    protected $fillable = ['marketplace', 'external_shop_id', 'shop_name', 'region', 'status', 'warehouse_id', 'sync_enabled', 'connected_at'];

    protected $hidden = ['id', 'tenant_id', 'warehouse_id'];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'circuit_open_until' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::CONNECTED;
    }

    public function circuitOpen(): bool
    {
        return $this->circuit_open_until !== null && $this->circuit_open_until->isFuture();
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return HasOne<MarketplaceCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(MarketplaceCredential::class);
    }

    /** @return HasMany<ListingVariant, $this> */
    public function listingVariants(): HasMany
    {
        return $this->hasMany(ListingVariant::class);
    }
}
