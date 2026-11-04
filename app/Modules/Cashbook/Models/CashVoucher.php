<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A receipt (phiếu thu) or payment (phiếu chi) outside orders and supplier payments. Never edited: a mistake is
 * voided and entered again.
 *
 * @property string $number
 * @property string $direction
 * @property string $party_kind
 * @property string $amount
 * @property bool $counts_in_pnl
 * @property Carbon $paid_at
 * @property Carbon|null $voided_at
 */
class CashVoucher extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const RECEIPT = 'receipt';

    public const PAYMENT = 'payment';

    public const DIRECTIONS = [self::RECEIPT, self::PAYMENT];

    public const PARTY_KINDS = ['customer', 'supplier', 'staff', 'carrier', 'other'];

    public const METHODS = ['cash', 'bank_transfer', 'card', 'ewallet', 'cod', 'other'];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'cash_account_id', 'cash_transfer_id', 'cash_voucher_type_id', 'customer_id', 'supplier_id', 'staff_id', 'created_by', 'voided_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'counts_in_pnl' => 'boolean', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    /** @return BelongsTo<CashVoucherType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(CashVoucherType::class, 'cash_voucher_type_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
