<?php

namespace App\Modules\Staff\Services;

use App\Modules\Accounting\Support\HouseholdTax;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherType;
use App\Modules\Cashbook\Services\CashVoucherService;
use App\Modules\Staff\Models\PayrollRun;
use App\Modules\Staff\Support\PayrollRules;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bảng lương: pay by attendance (or hours) + commission + allowance + bonus − advances − insurance − personal income
 * tax − other deductions. Draft figures are live; approving freezes them; paying books one "Lương nhân viên" voucher
 * per member in the cash book. Advances are the month's "Tạm ứng lương" vouchers paid to the member.
 */
class PayrollService
{
    public const SALARY_TYPE = 'Lương nhân viên';

    public const ADVANCE_TYPE = 'Tạm ứng lương';

    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffDirectory $staff,
        private readonly AttendanceService $attendance,
        private readonly CommissionService $commissions,
        private readonly CashVoucherService $vouchers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function month(string $period, ?int $onlyUser = null): array
    {
        [, , $label] = HouseholdTax::bounds($period);
        $run = PayrollRun::query()->where('period', $period)->first();
        $lines = $run && $run->status !== 'draft' && $run->lines !== null ? $run->lines : $this->compute($period, $run);
        if ($onlyUser !== null) {
            $ulid = DB::table('users')->where('id', $onlyUser)->value('ulid');
            $lines = array_values(array_filter($lines, fn ($l) => $l['id'] === $ulid));
        }

        return [
            'period' => $period, 'label' => $label, 'status' => $run->status ?? 'draft',
            'approved_at' => $run?->approved_at?->toIso8601String(), 'paid_at' => $run?->paid_at?->toIso8601String(),
            'approved_by' => $run?->approved_by ? DB::table('users')->where('id', $run->approved_by)->value('name') : null,
            'lines' => $lines,
            'advances' => $onlyUser === null ? $this->advances($period) : [],
            'totals' => [
                'net' => array_sum(array_column($lines, 'net')), 'commission' => array_sum(array_column($lines, 'commission')),
                'insurance' => array_sum(array_column($lines, 'insurance')), 'employer_insurance' => array_sum(array_column($lines, 'employer_insurance')),
                'pit' => array_sum(array_column($lines, 'pit')), 'count' => count($lines),
            ],
            'rules' => ['employee_insurance' => PayrollRules::EMPLOYEE_INSURANCE, 'employer_insurance' => PayrollRules::EMPLOYER_INSURANCE,
                'personal_deduction' => PayrollRules::PERSONAL_DEDUCTION, 'dependent_deduction' => PayrollRules::DEPENDENT_DEDUCTION],
        ];
    }

    /**
     * The month's "Tạm ứng lương" vouchers, newest first, for the list beside the payroll.
     *
     * @return list<array{name: string, member: string, paid_at: string|null, method: string|null, amount: float}>
     */
    private function advances(string $period): array
    {
        [$from, $to] = HouseholdTax::bounds($period);

        return DB::table('cash_vouchers as cv')->join('users as u', 'u.id', '=', 'cv.staff_id')
            ->where('cv.tenant_id', $this->context->id())->where('cv.direction', CashVoucher::PAYMENT)
            ->where('cv.type_name', self::ADVANCE_TYPE)->whereRaw(CashVoucher::COUNTS_SQL)
            ->whereBetween('cv.paid_at', [$from->utc(), $to->utc()])->orderByDesc('cv.paid_at')->limit(50)
            ->get(['u.name', 'u.ulid', 'cv.paid_at', 'cv.method', 'cv.amount'])
            ->map(fn ($r) => [
                'name' => (string) $r->name, 'member' => (string) $r->ulid, 'method' => $r->method, 'amount' => (float) $r->amount,
                'paid_at' => $r->paid_at ? \Carbon\CarbonImmutable::parse($r->paid_at, 'UTC')->toIso8601String() : null,
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function compute(string $period, ?PayrollRun $run): array
    {
        [$from, $to] = HouseholdTax::bounds($period);
        $att = collect($this->attendance->month($period)['members'])->keyBy('id');
        $com = collect($this->commissions->month($period)['members'])->keyBy('id');
        $advances = DB::table('cash_vouchers as cv')->where('cv.tenant_id', $this->context->id())->where('cv.direction', CashVoucher::PAYMENT)
            ->where('cv.type_name', self::ADVANCE_TYPE)->whereNotNull('cv.staff_id')->whereRaw(CashVoucher::COUNTS_SQL)
            ->whereBetween('cv.paid_at', [$from->utc(), $to->utc()])->groupBy('cv.staff_id')->selectRaw('cv.staff_id, SUM(cv.amount) as amount')->pluck('amount', 'staff_id');
        $adjust = $run->adjustments ?? [];

        $lines = [];
        foreach ($this->staff->members() as $m) {
            $p = StaffDirectory::profileView($m['profile']);
            $a = $att->get($m['id']);
            $c = $com->get($m['id']);
            $commission = (float) ($c['commission'] ?? 0);
            $pays = $p['pay_type'] === 'hour' ? $p['hourly_rate'] > 0 : $p['base_salary'] > 0;
            if (! $p['set'] && $commission == 0) {
                continue;
            }
            $days = (int) ($a['work_days'] ?? 0);
            $hours = (float) ($a['hours'] ?? 0);
            $standard = max(1, $p['standard_days']);
            $base = ! $pays ? 0.0 : ($p['pay_type'] === 'hour'
                ? round($hours * $p['hourly_rate'])
                : round($p['base_salary'] * min($days, $standard) / $standard));
            $adj = $adjust[$m['id']] ?? [];
            $bonus = (float) ($adj['bonus'] ?? 0);
            $deduction = (float) ($adj['deduction'] ?? 0);
            $advance = (float) ($advances[$m['user_id']] ?? 0);
            $insurance = round($p['insured_salary'] * PayrollRules::EMPLOYEE_INSURANCE / 100);
            $employer = round($p['insured_salary'] * PayrollRules::EMPLOYER_INSURANCE / 100);
            $gross = $base + $commission + $p['allowance'] + $bonus;
            $taxable = max(0, $gross - $insurance - PayrollRules::PERSONAL_DEDUCTION - PayrollRules::DEPENDENT_DEDUCTION * $p['dependents']);
            $pit = PayrollRules::pit($taxable);
            $net = $gross - $insurance - $pit - $advance - $deduction;

            $detail = [];
            $detail[] = $p['pay_type'] === 'hour'
                ? ['label' => 'Lương theo giờ '.number_format($p['hourly_rate'], 0, ',', '.')." × {$hours} giờ", 'amount' => $base]
                : ['label' => 'Lương cơ bản '.number_format($p['base_salary'], 0, ',', '.')." × {$days}/{$standard} công", 'amount' => $base];
            foreach ($c['items'] ?? [] as $item) {
                $detail[] = ['label' => 'Hoa hồng '.$item['name'].($item['unit'] === 'units' ? " ({$item['base']} sp)" : ' trên '.number_format($item['base'], 0, ',', '.')), 'amount' => $item['amount']];
            }
            if ($p['allowance'] > 0) {
                $detail[] = ['label' => 'Phụ cấp', 'amount' => $p['allowance']];
            }
            if ($bonus > 0) {
                $detail[] = ['label' => 'Thưởng'.(! empty($adj['note']) ? " ({$adj['note']})" : ''), 'amount' => $bonus];
            }
            if ($advance > 0) {
                $detail[] = ['label' => 'Tạm ứng trong tháng', 'amount' => -$advance];
            }
            if ($insurance > 0) {
                $detail[] = ['label' => 'BHXH 8% + BHYT 1,5% + BHTN 1% trên lương đóng bảo hiểm '.number_format($p['insured_salary'], 0, ',', '.'), 'amount' => -$insurance];
            }
            if ($pit > 0) {
                $detail[] = ['label' => 'Thuế TNCN (thu nhập tính thuế '.number_format($taxable, 0, ',', '.').')', 'amount' => -$pit];
            }
            if ($deduction > 0) {
                $detail[] = ['label' => 'Khấu trừ khác'.(! empty($adj['note']) ? " ({$adj['note']})" : ''), 'amount' => -$deduction];
            }

            $lines[] = [
                'id' => $m['id'], 'name' => $m['name'], 'title' => $p['title'] ?: $m['role'], 'pay_type' => $p['pay_type'],
                'days' => $days, 'standard_days' => $standard, 'hours' => $hours, 'base_pay' => $base, 'commission' => $commission,
                'allowance' => $p['allowance'], 'bonus' => $bonus, 'deduction' => $deduction, 'advance' => $advance,
                'insurance' => $insurance, 'employer_insurance' => $employer, 'pit' => $pit, 'gross' => $gross, 'net' => $net,
                'note' => $adj['note'] ?? null, 'detail' => $detail, 'profile' => $p,
            ];
        }

        return $lines;
    }

    /** @param  array{bonus?: float|int|null, deduction?: float|int|null, note?: string|null}  $data */
    public function adjust(string $period, string $memberUlid, array $data): void
    {
        $this->staff->userId($memberUlid);
        $run = $this->draft($period);
        $adjustments = $run->adjustments ?? [];
        $adjustments[$memberUlid] = ['bonus' => (float) ($data['bonus'] ?? 0), 'deduction' => (float) ($data['deduction'] ?? 0), 'note' => $data['note'] ?? null];
        $run->adjustments = $adjustments;
        $run->save();
    }

    public function approve(string $period): PayrollRun
    {
        $run = $this->draft($period);
        $lines = $this->compute($period, $run);
        if ($lines === []) {
            throw ValidationException::withMessages(['period' => 'Chưa có nhân viên nào trong bảng lương. Thiết lập lương cho nhân viên trước.']);
        }
        $run->fill(['status' => 'approved', 'lines' => $lines, 'approved_at' => now(), 'approved_by' => Auth::id()])->save();
        $this->audit->record('payroll.approved', $run, ['period' => $period, 'net' => array_sum(array_column($lines, 'net'))]);

        return $run;
    }

    public function reopen(string $period): PayrollRun
    {
        $run = PayrollRun::query()->where('period', $period)->firstOrFail();
        if ($run->status !== 'approved') {
            throw ValidationException::withMessages(['period' => $run->status === 'paid' ? 'Bảng lương đã chi, không mở lại được. Huỷ các phiếu chi lương trong Sổ quỹ nếu cần làm lại.' : 'Bảng lương chưa duyệt.']);
        }
        $run->fill(['status' => 'draft', 'lines' => null, 'approved_at' => null, 'approved_by' => null])->save();
        $this->audit->record('payroll.reopened', $run, ['period' => $period]);

        return $run;
    }

    /** Chi lương: one payment voucher per member with something to receive, in one go. */
    public function pay(string $period, string $method, ?string $account): PayrollRun
    {
        return DB::transaction(function () use ($period, $method, $account) {
            $run = PayrollRun::query()->where('period', $period)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'approved') {
                throw ValidationException::withMessages(['period' => $run->status === 'paid' ? 'Bảng lương này đã chi rồi.' : 'Duyệt bảng lương trước khi chi.']);
            }
            $type = $this->type(self::SALARY_TYPE, true);
            [, , $label] = HouseholdTax::bounds($period);
            foreach ($run->lines ?? [] as $line) {
                if ($line['net'] <= 0) {
                    continue;
                }
                $this->vouchers->record([
                    'direction' => CashVoucher::PAYMENT, 'type' => $type->ulid, 'party_kind' => 'staff', 'party_id' => $line['id'],
                    'method' => $method, 'account' => $account, 'amount' => $line['net'], 'reference' => "Lương {$period}",
                    'note' => "Lương {$label}",
                ], true);
            }
            $run->fill(['status' => 'paid', 'paid_at' => now(), 'paid_by' => Auth::id()])->save();
            $this->audit->record('payroll.paid', $run, ['period' => $period, 'method' => $method]);

            return $run;
        });
    }

    /** Tạm ứng: a "Tạm ứng lương" payment voucher to the member, taken off this month's pay. */
    public function advance(string $memberUlid, float $amount, string $method, ?string $note): CashVoucher
    {
        $this->staff->userId($memberUlid);

        return $this->vouchers->record([
            'direction' => CashVoucher::PAYMENT, 'type' => $this->type(self::ADVANCE_TYPE, false)->ulid, 'party_kind' => 'staff', 'party_id' => $memberUlid,
            'method' => $method, 'amount' => $amount, 'note' => $note ?: 'Tạm ứng lương',
        ]);
    }

    private function draft(string $period): PayrollRun
    {
        HouseholdTax::bounds($period);
        $run = PayrollRun::query()->firstOrCreate(['period' => $period], ['status' => 'draft']);
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['period' => 'Bảng lương đã duyệt. Bấm "Mở lại" để sửa.']);
        }

        return $run;
    }

    /** The cash book type, made when the business does not have it yet (salary costs count in the P&L, advances do not). */
    private function type(string $name, bool $pnl): CashVoucherType
    {
        return CashVoucherType::query()->firstOrCreate(['direction' => CashVoucher::PAYMENT, 'name' => $name], ['counts_in_pnl' => $pnl, 'pnl_group' => $pnl ? 'operating' : null]);
    }
}
