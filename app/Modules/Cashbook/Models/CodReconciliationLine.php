<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parcel of a COD statement.
 *
 * @property string $tracking_number
 * @property string $cod_amount
 * @property string $fee
 * @property string $status
 * @property string $booked
 * @property string|null $note
 * @property int|null $shipment_id
 */
class CodReconciliationLine extends Model
{
    use BelongsToTenant;

    /** The order was found and the COD equals what it still owes: booked in full. */
    public const MATCHED = 'matched';

    /** Found, but the COD differs from what it owes: the smaller of the two is booked, the rest is left to check. */
    public const MISMATCH = 'mismatch';

    /** No own-channel order with this tracking number. */
    public const NOT_FOUND = 'not_found';

    /** The order owes nothing more (paid, or reconciled before). */
    public const DUPLICATE = 'duplicate';

    /** A line without COD (fee only, e.g. a returned parcel). */
    public const NO_COD = 'no_cod';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['cod_amount' => 'decimal:2', 'fee' => 'decimal:2', 'booked' => 'decimal:2'];
    }

    /** @return BelongsTo<CodReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CodReconciliation::class, 'cod_reconciliation_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
