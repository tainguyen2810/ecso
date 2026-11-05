<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Services\CashAccountService;
use App\Modules\Cashbook\Services\CashClosingService;
use App\Modules\Cashbook\Services\CashVoucherService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\CashShift;
use App\Modules\Sales\Models\CashShiftMovement;
use App\Modules\Sales\Models\OrderPayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open, fund, close cashier shifts. A shift covers the money its cashier recorded (order_payments.created_by) from
 * opened_at until it is closed; the figures are frozen into the shift when it closes.
 */
class CashShiftService
{
    /** Vietnamese banknotes, for counting the drawer. */
    public const DENOMINATIONS = [500000, 200000, 100000, 50000, 20000, 10000, 5000, 2000, 1000, 500];

    public function __construct(private readonly AuditLogger $audit) {}

    public function current(User $user): ?CashShift
    {
        return CashShift::query()->where('open_user_id', $user->id)->first();
    }

    /** The open shift of the signed-in user, if any (money they take goes into it). */
    public function currentForAuth(): ?CashShift
    {
        $id = Auth::id();

        return $id === null ? null : CashShift::query()->where('open_user_id', $id)->first();
    }

    /**
     * Opens again the last closed shift of a cashier (a count or a sale corrected after closing). Only the cashier's
     * latest shift, only while they have no other shift open; the closing figures are kept in the audit log.
     */
    public function reopen(CashShift $shift, string $reason): CashShift
    {
        return DB::transaction(function () use ($shift, $reason) {
            $shift = CashShift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($shift->isOpen()) {
                throw ValidationException::withMessages(['shift' => 'Ca đang mở.']);
            }
            $latest = CashShift::query()->where('user_id', $shift->user_id)->latest('opened_at')->latest('id')->value('id');
            if ($latest !== $shift->id) {
                throw ValidationException::withMessages(['shift' => 'Chỉ mở lại được ca gần nhất của thu ngân này.']);
            }
            if (CashShift::query()->where('open_user_id', $shift->user_id)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Thu ngân đang có ca khác mở.']);
            }

            $closing = ['counted' => $shift->counted_cash, 'expected' => $shift->expected_cash, 'difference' => $shift->difference, 'handed_over' => $shift->handed_over, 'closed_at' => $shift->closed_at?->toIso8601String()];
            $shift->forceFill([
                'status' => CashShift::OPEN, 'open_user_id' => $shift->user_id, 'closed_at' => null, 'closed_by' => null,
                'expected_cash' => null, 'counted_cash' => null, 'difference' => null, 'handed_over' => null, 'denominations' => null, 'totals' => null,
                'closing_note' => trim(($shift->closing_note ? $shift->closing_note."\n" : '').'Mở lại: '.$reason),
            ])->save();
            $this->audit->record('shift.reopened', $shift, ['number' => $shift->number, 'reason' => $reason, 'previous_close' => $closing]);

            return $shift;
        });
    }

    /** What the last shift at this counter left in the drawer: the float the next shift starts from. */
    public function suggestedOpening(?Warehouse $warehouse): string
    {
        $last = CashShift::query()->where('status', CashShift::CLOSED)
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse?->id))
            ->latest('closed_at')->latest('id')->first();

        return $last ? bcsub((string) $last->counted_cash, (string) ($last->handed_over ?? 0), 2) : '0.00';
    }

    /**
     * @param  array{opening_cash: string|int|float, opening_note?: string|null}  $data
     */
    public function open(User $user, ?Warehouse $warehouse, array $data): CashShift
    {
        if ($this->current($user)) {
            throw ValidationException::withMessages(['shift' => 'Bạn đang có một ca chưa đóng. Đóng ca đó trước khi mở ca mới.']);
        }

        try {
            return DB::transaction(function () use ($user, $warehouse, $data) {
                $shift = new CashShift([
                    'number' => DocumentNumber::next('CA'),
                    'user_id' => $user->id,
                    'warehouse_id' => $warehouse?->id,
                    'status' => CashShift::OPEN,
                    'opened_at' => now(),
                    'opening_cash' => $this->money($data['opening_cash']),
                    'opening_note' => $data['opening_note'] ?? null,
                ]);
                $shift->forceFill(['open_user_id' => $user->id])->save();
                $this->audit->record('shift.opened', $shift, ['number' => $shift->number, 'opening_cash' => $shift->opening_cash]);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['shift' => 'Bạn đang có một ca chưa đóng. Đóng ca đó trước khi mở ca mới.']);
        }
    }

    /**
     * Cash put into or taken out of the drawer. Without a voucher type it is a move between the drawer and the
     * store's safe: the cash fund does not change. With one (in: a receipt type, out: a payment type) it is real
     * money in or out, booked as that voucher in the cash fund, so an expense paid from the drawer is an expense.
     *
     * @param  array{kind: string, amount: string|int|float, reason: string, voucher_type?: string|null}  $data
     */
    public function move(CashShift $shift, array $data): CashShiftMovement
    {
        return DB::transaction(function () use ($shift, $data) {
            $shift = CashShift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->assertOpen($shift);
            $amount = $this->money($data['amount']);
            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0.']);
            }
            if ($data['kind'] === CashShiftMovement::OUT) {
                $inDrawer = $this->totals($shift)['expected_cash'];
                if (bccomp($amount, $inDrawer, 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Không rút quá số tiền mặt đang có trong két ('.number_format((float) $inDrawer, 0, ',', '.').' ₫).']);
                }
            }

            $voucher = null;
            if ($data['voucher_type'] ?? null) {
                $user = Auth::user();
                $voucher = app(CashVoucherService::class)->record([
                    'direction' => $data['kind'] === CashShiftMovement::OUT ? CashVoucher::PAYMENT : CashVoucher::RECEIPT,
                    'type' => $data['voucher_type'],
                    'party_kind' => 'staff',
                    'party_id' => $user?->ulid,
                    'party_name' => $user?->name,
                    'method' => 'cash',
                    'amount' => $amount,
                    'reference' => $shift->number,
                    'note' => $data['reason'],
                ]);
            }

            $movement = new CashShiftMovement(['cash_shift_id' => $shift->id, 'kind' => $data['kind'], 'amount' => $amount, 'reason' => $data['reason']]);
            $movement->forceFill(['created_by' => Auth::id(), 'cash_voucher_id' => $voucher?->id])->save();
            $this->audit->record("shift.cash_{$data['kind']}", $shift, ['number' => $shift->number, 'amount' => $amount, 'reason' => $data['reason']]);

            return $movement;
        });
    }

    /**
     * @param  array{counted_cash?: string|int|float|null, denominations?: array<int|string, int>|null, handed_over?: string|int|float|null, closing_note?: string|null}  $data
     */
    public function close(CashShift $shift, array $data): CashShift
    {
        return DB::transaction(function () use ($shift, $data) {
            $shift = CashShift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->assertOpen($shift);

            $denominations = $this->denominations($data['denominations'] ?? null);
            $counted = $denominations !== null ? $this->sumDenominations($denominations) : $this->money($data['counted_cash'] ?? 0);
            if (isset($data['counted_cash']) && $denominations !== null && bccomp($counted, $this->money($data['counted_cash']), 2) !== 0) {
                throw ValidationException::withMessages(['counted_cash' => 'Tổng tiền đếm theo mệnh giá không khớp với số tiền thực tế.']);
            }
            $handedOver = $this->money($data['handed_over'] ?? 0);
            if (bccomp($handedOver, $counted, 2) > 0) {
                throw ValidationException::withMessages(['handed_over' => 'Không nộp quá số tiền đếm được trong két.']);
            }

            $totals = $this->totals($shift);
            $difference = bcsub($counted, $totals['expected_cash'], 2);
            if (bccomp($difference, '0', 2) !== 0 && trim((string) ($data['closing_note'] ?? '')) === '') {
                throw ValidationException::withMessages(['closing_note' => 'Tiền thực tế lệch so với hệ thống. Ghi lý do chênh lệch.']);
            }

            $shift->forceFill([
                'status' => CashShift::CLOSED,
                'closed_at' => now(),
                'closed_by' => Auth::id(),
                'expected_cash' => $totals['expected_cash'],
                'counted_cash' => $counted,
                'difference' => $difference,
                'handed_over' => $handedOver,
                'denominations' => $denominations,
                'totals' => $totals,
                'closing_note' => $data['closing_note'] ?? null,
                'open_user_id' => null,
            ])->save();
            // The drawer is part of the cash fund: a shortage or surplus found at closing is booked there too.
            $fund = CashAccount::query()->find(app(CashAccountService::class)->resolve(null, 'cash'));
            if ($fund) {
                app(CashClosingService::class)->bookDifference($fund, $difference, "két ca {$shift->number}: ".trim((string) ($data['closing_note'] ?? '')), $shift->number);
            }
            $this->audit->record('shift.closed', $shift, ['number' => $shift->number, 'expected' => $totals['expected_cash'], 'counted' => $counted, 'difference' => $difference]);

            return $shift;
        });
    }

    /**
     * Live figures of an open shift, or the frozen ones of a closed shift.
     *
     * @return array{orders: int, cancelled: int, revenue: string, by_method: array<string, string>, collected: string, cash_sales: string, cash_refunds: string, cash_in: string, cash_out: string, opening_cash: string, expected_cash: string}
     */
    public function totals(CashShift $shift): array
    {
        if (! $shift->isOpen() && $shift->totals) {
            /** @var array{orders: int, cancelled: int, revenue: string, by_method: array<string, string>, collected: string, cash_sales: string, cash_refunds: string, cash_in: string, cash_out: string, opening_cash: string, expected_cash: string} */
            return $shift->totals;
        }

        // Rows linked to the shift, plus older unlinked rows of the cashier taken while it was open (before the link existed).
        $orders = Order::query()->where('marketplace', Order::SOURCE_MANUAL)->where(fn ($q) => $q->where('cash_shift_id', $shift->id)
            ->orWhere(fn ($w) => $w->whereNull('cash_shift_id')->where('created_by', $shift->user_id)->where('created_at', '>=', $shift->opened_at)));
        $payments = OrderPayment::query()->whereNull('voided_at')->where(fn ($q) => $q->where('cash_shift_id', $shift->id)
            ->orWhere(fn ($w) => $w->whereNull('cash_shift_id')->where('created_by', $shift->user_id)->where('created_at', '>=', $shift->opened_at)));
        $byMethod = (clone $payments)->selectRaw("method, SUM(CASE WHEN kind = 'refund' THEN -amount ELSE amount END) as total")->groupBy('method')->pluck('total', 'method');
        $cash = (clone $payments)->where('method', 'cash')->selectRaw('kind, SUM(amount) as total')->groupBy('kind')->pluck('total', 'kind');
        $moves = CashShiftMovement::query()->where('cash_shift_id', $shift->id)->selectRaw('kind, SUM(amount) as total')->groupBy('kind')->pluck('total', 'kind');

        $cashSales = $this->money($cash[OrderPayment::PAYMENT] ?? 0);
        $cashRefunds = $this->money($cash[OrderPayment::REFUND] ?? 0);
        $in = $this->money($moves[CashShiftMovement::IN] ?? 0);
        $out = $this->money($moves[CashShiftMovement::OUT] ?? 0);
        $opening = $this->money($shift->opening_cash);

        return [
            'orders' => (clone $orders)->where('status', '!=', OrderStatus::CANCELLED)->count(),
            'cancelled' => (clone $orders)->where('status', OrderStatus::CANCELLED)->count(),
            'revenue' => $this->money((clone $orders)->where('status', '!=', OrderStatus::CANCELLED)->sum('total')),
            'by_method' => collect(OrderPayment::METHODS)->mapWithKeys(fn ($m) => [$m => $this->money($byMethod[$m] ?? 0)])->all(),
            'collected' => $this->money($byMethod->sum()),
            'cash_sales' => $cashSales,
            'cash_refunds' => $cashRefunds,
            'cash_in' => $in,
            'cash_out' => $out,
            'opening_cash' => $opening,
            'expected_cash' => bcadd(bcsub(bcadd(bcsub(bcadd($opening, $cashSales, 2), $cashRefunds, 2), $in, 2), $out, 2), '0', 2),
        ];
    }

    private function assertOpen(CashShift $shift): void
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'Ca đã đóng.']);
        }
    }

    /**
     * @param  array<int|string, int>|null  $input
     * @return array<string, int>|null
     */
    private function denominations(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $out = [];
        foreach ($input as $value => $count) {
            if (! in_array((int) $value, self::DENOMINATIONS, true) || (int) $count < 0) {
                throw ValidationException::withMessages(['denominations' => 'Mệnh giá hoặc số tờ không hợp lệ.']);
            }
            if ((int) $count > 0) {
                $out[(string) (int) $value] = (int) $count;
            }
        }

        return $out;
    }

    /** @param  array<string, int>  $denominations */
    private function sumDenominations(array $denominations): string
    {
        $sum = '0';
        foreach ($denominations as $value => $count) {
            $sum = bcadd($sum, bcmul((string) $value, (string) $count, 0), 0);
        }

        return bcadd($sum, '0', 2);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 2);
    }
}
