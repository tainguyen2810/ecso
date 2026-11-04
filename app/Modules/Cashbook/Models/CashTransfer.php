<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Money moved between two funds of the business (CQ-000001): out of one, into the other, in one row, so the total
 * across funds does not change and nothing counts as income or expense. A bank fee is a linked payment voucher.
 * Voided, never edited.
 *
 * @property string $number
 * @property string $amount
 * @property string $fee
 * @property Carbon $transferred_at
 * @property Carbon|null $voided_at
 */
class CashTransfer extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'from_account_id', 'to_account_id', 'created_by', 'voided_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fee' => 'decimal:2', 'transferred_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'from_account_id');
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'to_account_id');
    }

    /** @return HasOne<CashVoucher, $this> */
    public function feeVoucher(): HasOne
    {
        return $this->hasOne(CashVoucher::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
