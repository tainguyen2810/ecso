<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A carrier's COD statement, imported and booked (CodReconciliationService).
 *
 * @property string $number
 * @property string $carrier
 * @property string|null $file_name
 * @property Carbon $remitted_at
 * @property string $total_cod
 * @property string $total_fee
 * @property string $total_booked
 * @property int $lines_count
 * @property int $matched_count
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 */
class CodReconciliation extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const CARRIERS = ['ghn' => 'GHN', 'viettelpost' => 'Viettel Post', 'spx' => 'SPX Express', 'jt' => 'J&T Express', 'other' => 'Hãng khác'];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'cash_account_id', 'fee_voucher_id', 'created_by', 'voided_by'];

    protected function casts(): array
    {
        return ['remitted_at' => 'datetime', 'voided_at' => 'datetime', 'total_cod' => 'decimal:2', 'total_fee' => 'decimal:2', 'total_booked' => 'decimal:2'];
    }

    /** @return HasMany<CodReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CodReconciliationLine::class);
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    /** @return BelongsTo<CashVoucher, $this> */
    public function feeVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class, 'fee_voucher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
