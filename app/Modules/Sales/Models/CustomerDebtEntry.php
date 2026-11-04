<?php

namespace App\Modules\Sales\Models;

use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer debt document that is not an order: an opening balance, an adjustment (with its reason), or the receipt
 * of such a balance (linked to its cash book voucher). The amount is signed: positive adds to the debt. Entries are
 * never edited or deleted, only voided with a reason.
 *
 * @property Carbon $entry_date
 * @property Carbon|null $due_date
 */
class CustomerDebtEntry extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const OPENING = 'opening';

    public const ADJUSTMENT = 'adjustment';

    public const RECEIPT = 'receipt';

    public const KINDS = [self::OPENING, self::ADJUSTMENT, self::RECEIPT];

    protected $hidden = ['id', 'tenant_id', 'customer_id', 'cash_voucher_id', 'created_by', 'voided_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'entry_date' => 'date', 'due_date' => 'date', 'voided_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CashVoucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class, 'cash_voucher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
