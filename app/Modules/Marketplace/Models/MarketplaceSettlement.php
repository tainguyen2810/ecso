<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a marketplace charged and paid out for one order (from its settlement report or, later, its API).
 *
 * @property Carbon|null $settled_at
 */
class MarketplaceSettlement extends Model
{
    use BelongsToTenant;

    public const AMOUNTS = ['order_amount', 'commission_fee', 'payment_fee', 'service_fee', 'fixed_fee', 'shipping_fee', 'net_amount'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return array_fill_keys(self::AMOUNTS, 'decimal:2') + ['settled_at' => 'datetime'];
    }
}
