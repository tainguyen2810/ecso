<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Cashbook\Models\CashClosing;
use App\Modules\Cashbook\Models\CashCount;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Cashbook\Support\PeriodLock;
use App\Shared\Support\ReportRange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing the books up to a day (khóa sổ) and counting a fund (kiểm quỹ). Neither changes a money row: a closing
 * only stops later changes to its period, and a count books its difference as a new voucher dated now.
 */
class CashClosingService
{
    /** Name of the voucher type a count's difference is booked under (created when first needed). */
    public const COUNT_TYPE = 'Chênh lệch kiểm quỹ';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CashFlows $flows,
        private readonly CashVoucherService $vouchers,
    ) {}

    /** Closes the books up to the end of $until (a day before today, in the business's time zone). */
    public function close(string $until, ?string $note = null): CashClosing
    {
        return DB::transaction(function () use ($until, $note) {
            $day = ReportRange::date($until);
            if ($day->gte(ReportRange::now()->startOfDay())) {
                throw ValidationException::withMessages(['closed_until' => 'Chỉ khóa sổ đến hết hôm qua hoặc trước đó.']);
            }
            $current = PeriodLock::current();
            if ($current && $day->lte(ReportRange::date($current->closed_until->toDateString()))) {
                throw ValidationException::withMessages(['closed_until' => 'Sổ đã khóa đến '.$current->closed_until->format('d/m/Y').'. Chọn ngày sau ngày đó.']);
            }
            $balances = $this->flows->balances($day->endOfDay());
            $closing = new CashClosing([
                'closed_until' => $day->toDateString(),
                'balances' => collect($balances)->map(fn ($v) => (string) $v)->all(),
                'note' => $note,
            ]);
            $closing->forceFill(['created_by' => Auth::id()])->save();
            $this->audit->record('cash.closed', $closing, ['closed_until' => $day->toDateString(), 'total' => array_sum(array_map('floatval', $balances))]);

            return $closing;
        });
    }

    /** Reopens the current closing; the one before it, if any, applies again. */
    public function reopen(CashClosing $closing, string $reason): CashClosing
    {
        return DB::transaction(function () use ($closing, $reason) {
            $closing = CashClosing::query()->lockForUpdate()->findOrFail($closing->id);
            if ($closing->reopened_at !== null || PeriodLock::current()?->id !== $closing->id) {
                throw ValidationException::withMessages(['closing' => 'Chỉ mở được lần khóa sổ gần nhất.']);
            }
            $closing->forceFill(['reopened_at' => now(), 'reopened_by' => Auth::id(), 'reopen_reason' => $reason])->save();
            $this->audit->record('cash.reopened', $closing, ['closed_until' => $closing->closed_until->toDateString(), 'reason' => $reason]);

            return $closing;
        });
    }

    /**
     * Counts the money in a fund now. A difference with the balance on record becomes a receipt (more counted) or a
     * payment (less counted) voucher of that fund, so its balance then equals what was counted.
     */
    public function count(CashAccount $account, string|int|float $counted, ?string $note = null): CashCount
    {
        return DB::transaction(function () use ($account, $counted, $note) {
            $account = CashAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (! $account->is_active) {
                throw ValidationException::withMessages(['counted_amount' => 'Quỹ đã ngừng dùng.']);
            }
            $counted = bcadd((string) $counted, '0', 2);
            if (bccomp($counted, '0', 2) < 0) {
                throw ValidationException::withMessages(['counted_amount' => 'Số tiền đếm được không âm.']);
            }
            $system = bcadd((string) ($this->flows->balances()[$account->id] ?? '0'), '0', 2);
            $difference = bcsub($counted, $system, 2);

            $voucher = $this->bookDifference($account, $difference, 'khi kiểm quỹ'.($note ? ": {$note}" : ''));

            $count = new CashCount([
                'cash_account_id' => $account->id,
                'counted_at' => now(),
                'system_balance' => $system,
                'counted_amount' => $counted,
                'difference' => $difference,
                'cash_voucher_id' => $voucher?->id,
                'note' => $note,
            ]);
            $count->forceFill(['created_by' => Auth::id()])->save();
            $this->audit->record('cash.counted', $count, ['account' => $account->name, 'system' => $system, 'counted' => $counted, 'difference' => $difference, 'voucher' => $voucher?->number]);

            return $count;
        });
    }

    /**
     * Books a counted difference (counted − on record) as a receipt (more money) or payment (less) voucher of the
     * fund, type "Chênh lệch kiểm quỹ", dated now. Null when there is no difference. Used by fund counts and by the
     * drawer count when a POS shift closes.
     */
    public function bookDifference(CashAccount $account, string $difference, string $note, ?string $reference = null): ?CashVoucher
    {
        if (bccomp($difference, '0', 2) === 0) {
            return null;
        }
        $direction = bccomp($difference, '0', 2) > 0 ? CashVoucher::RECEIPT : CashVoucher::PAYMENT;
        $this->vouchers->ensureDefaultTypes();
        $type = CashVoucherType::query()->firstOrCreate(
            ['direction' => $direction, 'name' => self::COUNT_TYPE],
            ['counts_in_pnl' => false, 'pnl_group' => $direction === CashVoucher::PAYMENT ? 'operating' : null, 'description' => 'Thừa hoặc thiếu tiền khi kiểm quỹ, kiểm két cuối ca.'],
        );

        return $this->vouchers->record([
            'direction' => $direction,
            'type' => $type->ulid,
            'party_kind' => 'other',
            'party_name' => $account->name,
            'method' => $account->methods[0] ?? self::method($account->kind),
            'account' => $account->ulid,
            'amount' => ltrim($difference, '-'),
            'counts_in_pnl' => $type->counts_in_pnl,
            'reference' => $reference,
            'note' => ($direction === CashVoucher::RECEIPT ? 'Thừa ' : 'Thiếu ').$note,
        ], system: true);
    }

    private static function method(string $kind): string
    {
        return match ($kind) {
            'cash' => 'cash', 'bank' => 'bank_transfer', 'ewallet' => 'ewallet', 'cod' => 'cod', default => 'other',
        };
    }
}
