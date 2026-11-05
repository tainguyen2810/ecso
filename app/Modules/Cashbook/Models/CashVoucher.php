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
 * @property string|null $type_name
 * @property string $party_kind
 * @property string $amount
 * @property bool $counts_in_pnl
 * @property Carbon $paid_at
 * @property string $status
 * @property Carbon|null $approved_at
 * @property Carbon|null $voided_at
 * @property bool $void_reversed
 */
class CashVoucher extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const RECEIPT = 'receipt';

    public const PAYMENT = 'payment';

    public const DIRECTIONS = [self::RECEIPT, self::PAYMENT];

    /** Counts in funds and reports. */
    public const COMPLETED = 'completed';

    /** A large payment waiting for approval: listed, but counted nowhere until approved. */
    public const PENDING = 'pending';

    /** Approval refused: kept, voided. */
    public const REJECTED = 'rejected';

    /**
     * SQL (alias cv): the voucher counts on its own day. A voucher voided after its day was closed still does; its
     * void is booked the other way on the day of the void (REVERSAL_SQL).
     */
    public const COUNTS_SQL = "cv.status = 'completed' AND (cv.voided_at IS NULL OR cv.void_reversed = 1)";

    public const REVERSAL_SQL = "cv.status = 'completed' AND cv.void_reversed = 1";

    public const PARTY_KINDS = ['customer', 'supplier', 'staff', 'carrier', 'other'];

    public const METHODS = ['cash', 'bank_transfer', 'card', 'ewallet', 'cod', 'other'];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'cash_account_id', 'cash_transfer_id', 'cash_voucher_type_id', 'customer_id', 'supplier_id', 'staff_id', 'created_by', 'voided_by', 'approved_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'counts_in_pnl' => 'boolean', 'paid_at' => 'datetime', 'voided_at' => 'datetime', 'approved_at' => 'datetime', 'void_reversed' => 'boolean'];
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
