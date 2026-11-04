<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cash count (kiểm quỹ): the money actually counted in a fund against its balance on record. A difference is
 * booked as a receipt or payment voucher of type "Chênh lệch kiểm quỹ" so the fund then shows what was counted.
 *
 * @property Carbon $counted_at
 * @property string $system_balance
 * @property string $counted_amount
 * @property string $difference
 * @property string|null $note
 */
class CashCount extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'cash_account_id', 'cash_voucher_id', 'created_by'];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime', 'system_balance' => 'decimal:2', 'counted_amount' => 'decimal:2', 'difference' => 'decimal:2'];
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
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
