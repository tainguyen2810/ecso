<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Staff\Models\AttendanceEntry;
use App\Modules\Staff\Models\CommissionRule;
use App\Modules\Staff\Models\StaffProfile;
use App\Modules\Staff\Services\AttendanceService;
use App\Modules\Staff\Services\CommissionService;
use App\Modules\Staff\Services\PayrollService;
use App\Modules\Staff\Services\StaffDirectory;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Nhân viên › Chấm công, Hoa hồng, Bảng lương. With staff.manage a manager sees and sets everyone; any other member
 * sees only their own figures (and their payslip once the payroll is approved) and checks in on the phone.
 */
class StaffController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly StaffDirectory $staff) {}

    public function attendance(Request $request, AttendanceService $attendance): JsonResponse
    {
        return response()->json(['data' => [...$attendance->month($this->month($request), $this->scope($request)), 'manage' => $this->manages()]]);
    }

    /** Today's check-in of the member signed in. */
    public function me(Request $request): JsonResponse
    {
        $entry = AttendanceEntry::query()->where('user_id', $request->user()->id)->where('day', ReportRange::now()->toDateString())->first();
        $shift = DB::table('cash_shifts')->where('tenant_id', $this->context->id())->where('open_user_id', $request->user()->id)->value('number');
        $zone = ReportRange::timezone();

        return response()->json(['data' => [
            'check_in' => $entry?->check_in?->setTimezone($zone)->format('H:i'), 'check_out' => $entry?->check_out?->setTimezone($zone)->format('H:i'),
            'manual' => $entry?->source === 'manual', 'open_shift' => $shift,
        ]]);
    }

    public function check(Request $request, AttendanceService $attendance): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['in', 'out'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $entry = $attendance->check($request->user()->id, $data['action'], isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);
        $zone = ReportRange::timezone();

        return response()->json(['data' => ['check_in' => $entry->check_in?->setTimezone($zone)->format('H:i'), 'check_out' => $entry->check_out?->setTimezone($zone)->format('H:i')]]);
    }

    public function setDay(Request $request, string $member, string $day, AttendanceService $attendance): JsonResponse
    {
        $this->guard();
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['work', 'leave', 'off', 'clear'])],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'excuse_late' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $attendance->setDay($this->staff->userId($member), $day, $data);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function members(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'role' => $m['role'], 'profile' => StaffDirectory::profileView($m['profile'])], $this->staff->members())]);
    }

    public function saveProfile(Request $request, string $member): JsonResponse
    {
        $this->guard();
        $userId = $this->staff->userId($member);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'pay_type' => ['required', Rule::in(['month', 'hour'])],
            'base_salary' => ['numeric', 'min:0', 'max:9999999999'],
            'hourly_rate' => ['numeric', 'min:0', 'max:99999999'],
            'standard_days' => ['integer', 'min:1', 'max:31'],
            'allowance' => ['numeric', 'min:0', 'max:9999999999'],
            'insured_salary' => ['numeric', 'min:0', 'max:9999999999'],
            'dependents' => ['integer', 'min:0', 'max:20'],
            'sales_target' => ['numeric', 'min:0', 'max:99999999999'],
            'shift_start' => ['date_format:H:i'],
            'shift_end' => ['date_format:H:i', 'after:shift_start'],
        ]);
        $profile = StaffProfile::query()->updateOrCreate(['user_id' => $userId], $data);

        return response()->json(['data' => StaffDirectory::profileView($profile)]);
    }

    public function commissions(Request $request, CommissionService $commissions): JsonResponse
    {
        $data = $commissions->month($this->month($request), $this->scope($request));
        $data['rules'] = $this->manages() ? CommissionRule::query()->orderBy('id')->get()->map(fn ($r) => CommissionService::ruleView($r))->values() : [];
        $data['manage'] = $this->manages();

        return response()->json(['data' => $data]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $this->guard();
        $rule = CommissionRule::query()->create($this->ruleData($request));

        return response()->json(['data' => CommissionService::ruleView($rule)], 201);
    }

    public function updateRule(Request $request, CommissionRule $rule): JsonResponse
    {
        $this->guard();
        $rule->fill($this->ruleData($request, true))->save();

        return response()->json(['data' => CommissionService::ruleView($rule)]);
    }

    public function destroyRule(CommissionRule $rule): JsonResponse
    {
        $this->guard();
        $rule->delete();

        return response()->json(null, 204);
    }

    public function payroll(Request $request, PayrollService $payroll): JsonResponse
    {
        $period = $this->month($request);
        if ($this->manages()) {
            return response()->json(['data' => [...$payroll->month($period), 'manage' => true]]);
        }
        // A member sees their own payslip once the payroll is approved.
        $data = $payroll->month($period, $request->user()->id);
        if ($data['status'] === 'draft') {
            $data['lines'] = [];
        }

        return response()->json(['data' => [...$data, 'manage' => false]]);
    }

    public function adjust(Request $request, string $period, string $member, PayrollService $payroll): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'bonus' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'deduction' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $payroll->adjust($this->period($period), $member, $data);

        return response()->json(['data' => $payroll->month($period)]);
    }

    public function approve(string $period, PayrollService $payroll): JsonResponse
    {
        $this->guard();
        $payroll->approve($this->period($period));

        return response()->json(['data' => $payroll->month($period)]);
    }

    public function reopen(string $period, PayrollService $payroll): JsonResponse
    {
        $this->guard();
        $payroll->reopen($this->period($period));

        return response()->json(['data' => $payroll->month($period)]);
    }

    public function pay(Request $request, string $period, PayrollService $payroll): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['method' => ['required', Rule::in(['cash', 'bank_transfer'])], 'account' => ['nullable', 'string', 'max:40']]);
        $payroll->pay($this->period($period), $data['method'], $data['account'] ?? null);

        return response()->json(['data' => $payroll->month($period)]);
    }

    public function advance(Request $request, PayrollService $payroll): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'member' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:1000', 'max:9999999999'],
            'method' => ['required', Rule::in(CashVoucher::METHODS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $voucher = $payroll->advance($data['member'], (float) $data['amount'], $data['method'], $data['note'] ?? null);

        return response()->json(['data' => ['number' => $voucher->number, 'status' => $voucher->status]], 201);
    }

    /** @return array<string, mixed> */
    private function ruleData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:100'],
            'kind' => [$req, Rule::in(CommissionRule::KINDS)],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'category_id' => ['nullable', 'string'],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'user_ids' => ['nullable', 'array', 'max:200'],
            'user_ids.*' => ['string'],
            'is_active' => ['boolean'],
        ]);
        if (array_key_exists('category_id', $data)) {
            $data['category_id'] = $data['category_id'] ? (DB::table('categories')->where('tenant_id', $this->context->id())->where('ulid', $data['category_id'])->value('id') ?? abort(422, 'Danh mục không tồn tại.')) : null;
        }
        if (array_key_exists('user_ids', $data)) {
            $data['user_ids'] = $data['user_ids'] ? array_map(fn ($u) => $this->staff->userId($u), $data['user_ids']) : null;
        }
        if (($data['kind'] ?? null) === 'category_unit' && empty($data['category_id'])) {
            abort(422, 'Chọn danh mục sản phẩm cho cách tính theo sản phẩm.');
        }
        foreach (['rate', 'amount', 'threshold'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = (float) ($data[$k] ?? 0);
            }
        }

        return $data;
    }

    private function manages(): bool
    {
        return $this->context->can('staff.manage');
    }

    private function guard(): void
    {
        abort_unless($this->manages(), 403);
    }

    private function scope(Request $request): ?int
    {
        return $this->manages() ? null : (int) $request->user()->id;
    }

    private function month(Request $request): string
    {
        $m = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']])['month'] ?? null;

        return $m ?? ReportRange::now()->format('Y-m');
    }

    private function period(string $period): string
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $period) === 1, 404);

        return $period;
    }
}
