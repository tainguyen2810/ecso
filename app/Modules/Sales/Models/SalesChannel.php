<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales channel run by the seller (shop counter, Facebook, Zalo, website...). Orders of these channels are
 * created and processed in ECSO, unlike marketplace orders which are owned by the marketplace.
 */
class SalesChannel extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const DEFAULT_NAME = 'Bán tại cửa hàng';

    public const TYPES = ['pos', 'store', 'facebook', 'zalo', 'website', 'phone', 'other'];

    protected $fillable = ['name', 'type', 'is_active'];

    protected $hidden = ['id', 'tenant_id', 'warehouse_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
