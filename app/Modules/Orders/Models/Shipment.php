<?php

namespace App\Modules\Orders\Models;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One parcel of an order at a carrier (vận đơn). The order's shipping_carrier / tracking_number mirror its main
 * shipment, so everything that reads them keeps working. Fees here are for display: the profit report takes carrier
 * fees only from the COD reconciliation voucher and manual vouchers, never from this table.
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $carrier
 * @property string|null $carrier_name
 * @property string $source
 * @property string|null $tracking_number
 * @property string $status
 * @property string $cod_amount
 * @property string $cod_status
 * @property int $attempts
 * @property Carbon|null $ship_by_at
 * @property Carbon|null $picked_up_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $last_event_at
 * @property int $packages
 * @property int|null $weight_g
 * @property string|null $fee_estimated
 * @property string|null $fee_actual
 * @property string|null $failure_reason
 */
class Shipment extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SELF = 'self';

    public const SOURCE_API = 'api';

    public const SOURCE_MARKETPLACE = 'marketplace';

    public const SOURCE_IMPORT = 'import';

    public const COD_NONE = 'none';

    /** Not delivered yet: the carrier will collect it. */
    public const COD_PENDING = 'pending';

    /** Delivered: the carrier holds the money until it pays the shop. */
    public const COD_COLLECTED = 'collected';

    /** Paid to the shop (COD reconciliation). */
    public const COD_REMITTED = 'remitted';

    public const COD_RETURNED = 'returned';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cod_amount' => 'decimal:2',
            'cod_collected' => 'decimal:2',
            'fee_estimated' => 'decimal:2',
            'fee_actual' => 'decimal:2',
            'packages' => 'integer',
            'attempts' => 'integer',
            'ship_by_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<ShipmentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
