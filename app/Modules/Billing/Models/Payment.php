<?php

namespace App\Modules\Billing\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $provider
 * @property string $provider_txn_id
 * @property string $amount
 * @property string $status
 * @property Carbon $received_at
 */
class Payment extends Model
{
    use BelongsToTenant;

    /** Paid the invoice in full. */
    public const APPLIED = 'applied';

    /** Less than the invoice amount: recorded, invoice stays pending, needs follow-up. */
    public const UNDERPAID = 'underpaid';

    /** Invoice was no longer pending (already paid or cancelled): recorded for refund/follow-up. */
    public const UNAPPLIED = 'unapplied';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime', 'raw' => 'array'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
