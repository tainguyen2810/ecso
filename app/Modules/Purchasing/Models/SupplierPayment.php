<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money paid to a supplier, for one purchase order or on account, or (kind "refund") money the supplier paid back.
 * Voided instead of edited.
 *
 * @property string $kind
 * @property Carbon $paid_at
 * @property Carbon|null $voided_at
 */
class SupplierPayment extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const METHODS = ['cash', 'bank_transfer', 'card', 'other'];

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    /** SQL of a row's amount as paid to the supplier: a refund counts negative. */
    public const SIGNED_SQL = "CASE WHEN kind = 'refund' THEN -amount ELSE amount END";

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'cash_account_id', 'supplier_id', 'purchase_order_id', 'created_by', 'voided_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
