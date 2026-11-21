<?php

namespace App\Modules\Staff\Services;

use App\Modules\Accounting\Support\HouseholdTax;
use App\Modules\Staff\Models\AttendanceEntry;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chấm công: one cell per member and day. The counter's cash shifts count by themselves (opened = in, closed = out);
 * members who do not sell at the counter check in and out on the phone; a manager can set a day by hand (hours,
 * paid leave, unpaid day off, late excused), which then wins over both.
 */
class AttendanceService
{
    /** Minutes after the shift start before an arrival counts as late. */
    public const LATE_GRACE = 10;

    public function __construct(private readonly StaffDirectory $staff) {}

    /** @return array<string, mixed> */
    public function month(string $period, ?int $onlyUser = null): array
    {
        [$from, $to, $label] = HouseholdTax::bounds($period);
        $zone = ReportRange::timezone();
        $today = ReportRange::now()->startOfDay();
        $members = $this->staff->members($onlyUser);
        $ids = array_column($members, 'user_id');

        $shifts = DB::table('cash_shifts')->where('tenant_id', app(TenantContext::class)->id())->whereIn('user_id', $ids)
            ->whereBetween('opened_at', [$from->utc(), $to->utc()])->orderBy('opened_at')->get(['user_id', 'number', 'opened_at', 'closed_at']);
        $entries = AttendanceEntry::query()->whereIn('user_id', $ids)->whereBetween('day', [$from->toDateString(), $to->toDateString()])->get();

        /** @var array<int, array<int, array<string, mixed>>> $raw */
        $raw = [];
        foreach ($shifts as $s) {
            $in = CarbonImmutable::parse($s->opened_at, 'UTC')->setTimezone($zone);
            $out = $s->closed_at ? CarbonImmutable::parse($s->closed_at, 'UTC')->setTimezone($zone) : null;
            $d = &$raw[(int) $s->user_id][$in->day];
            $d['in'] = isset($d['in']) && $d['in'] < $in ? $d['in'] : $in;
            $d['out'] = $out === null ? ($d['out'] ?? null) : (isset($d['out']) && $d['out'] > $out ? $d['out'] : $out);
            $d['source'] = 'shift';
            $d['shifts'][] = (string) $s->number;
            unset($d);
        }
        foreach ($entries as $e) {
            $d = &$raw[$e->user_id][$e->day->day];
            $in = $e->check_in?->toImmutable()->setTimezone($zone);
            $out = $e->check_out?->toImmutable()->setTimezone($zone);
            if ($e->source === 'manual') {
                $d = ['in' => $in, 'out' => $out, 'open' => false, 'source' => 'manual', 'shifts' => $d['shifts'] ?? [], 'status' => $e->status, 'excuse' => $e->excuse_late, 'note' => $e->note];
            } else {
                $ins = array_filter([$d['in'] ?? null, $in]);
                $outs = array_filter([$d['out'] ?? null, $out]);
                $d['in'] = $ins === [] ? null : min($ins);
                $d['out'] = $outs === [] ? null : max($outs);
                $d['source'] ??= 'phone';
                $d['located'] = $e->lat !== null;
            }
            unset($d);
        }

        $rows = [];
        foreach ($members as $m) {
            $profile = StaffDirectory::profileView($m['profile']);
            [$sh, $sm] = array_map('intval', explode(':', $profile['shift_start']));
            [$eh, $em] = array_map('intval', explode(':', $profile['shift_end']));
            $standardHours = max(1, (($eh * 60 + $em) - ($sh * 60 + $sm)) / 60);
            $days = [];
            $hours = 0.0;
            $workDays = 0;
            $late = 0;
            foreach ($raw[$m['user_id']] ?? [] as $n => $d) {
                $date = $from->setDay($n);
                $status = $d['status'] ?? 'work';
                $cell = ['status' => $status, 'hours' => 0.0, 'late' => 0, 'in' => $d['in']?->format('H:i'), 'out' => $d['out']?->format('H:i'),
                    'source' => $d['source'] ?? 'phone', 'shifts' => $d['shifts'] ?? [], 'note' => $d['note'] ?? null, 'located' => $d['located'] ?? false];
                if ($status === 'leave') {
                    $cell['hours'] = $standardHours;
                    $workDays++;
                } elseif ($status === 'work' && $d['in']) {
                    $workDays++;
                    if ($d['out']) {
                        $cell['hours'] = round(max(0, $d['in']->diffInMinutes($d['out'])) / 30) / 2;
                    } else {
                        $cell['status'] = $date->lt($today) ? 'no_out' : 'open';
                    }
                    $start = $date->setTime($sh, $sm);
                    $minutes = (int) floor($start->diffInMinutes($d['in'], false));
                    if ($minutes > self::LATE_GRACE && ! ($d['excuse'] ?? false)) {
                        $cell['late'] = $minutes;
                        if ($cell['status'] === 'work') {
                            $cell['status'] = 'late';
                        }
                        $late++;
                    }
                }
                $hours += $cell['hours'];
                $days[$n] = $cell;
            }
            ksort($days);
            $rows[] = ['id' => $m['id'], 'name' => $m['name'], 'title' => $profile['title'] ?: $m['role'], 'shift' => "{$profile['shift_start']}–{$profile['shift_end']}",
                'days' => (object) $days, 'hours' => $hours, 'work_days' => $workDays, 'late' => $late];
        }

        return [
            'period' => $period, 'label' => $label, 'days' => $from->daysInMonth,
            'today' => $today->format('Y-m') === $period ? $today->day : null,
            'weekends' => array_values(array_filter(range(1, $from->daysInMonth), fn ($n) => $from->setDay($n)->isSunday())),
            'members' => $rows,
        ];
    }

    /** Vào ca / Tan ca on the phone, for the member signed in, today on the shop's clock. */
    public function check(int $userId, string $action, ?float $lat, ?float $lng): AttendanceEntry
    {
        $day = ReportRange::now()->toDateString();
        $entry = AttendanceEntry::query()->firstOrNew(['user_id' => $userId, 'day' => $day]);
        if ($entry->exists && $entry->source === 'manual') {
            throw ValidationException::withMessages(['action' => 'Hôm nay quản lý đã chấm công cho bạn.']);
        }
        if ($action === 'in') {
            if ($entry->check_in) {
                throw ValidationException::withMessages(['action' => 'Bạn đã vào ca lúc '.$entry->check_in->setTimezone(ReportRange::timezone())->format('H:i').'.']);
            }
            $entry->fill(['check_in' => now(), 'status' => 'work', 'source' => 'phone', 'lat' => $lat, 'lng' => $lng]);
        } else {
            if (! $entry->check_in) {
                throw ValidationException::withMessages(['action' => 'Bạn chưa vào ca hôm nay.']);
            }
            $entry->check_out = now();
        }
        $entry->save();

        return $entry;
    }

    /**
     * A manager sets one day by hand: work with times, paid leave, unpaid day off, or "clear" to go back to what the
     * counter and the phone recorded.
     *
     * @param  array{status: string, check_in?: string|null, check_out?: string|null, excuse_late?: bool, note?: string|null}  $data
     */
    public function setDay(int $userId, string $day, array $data): void
    {
        if ($data['status'] === 'clear') {
            AttendanceEntry::query()->where('user_id', $userId)->where('day', $day)->delete();

            return;
        }
        $zone = ReportRange::timezone();
        $at = fn (?string $hm) => $hm ? CarbonImmutable::parse("{$day} {$hm}", $zone)->utc() : null;
        $in = $data['status'] === 'work' ? $at($data['check_in'] ?? null) : null;
        $out = $data['status'] === 'work' ? $at($data['check_out'] ?? null) : null;
        if ($data['status'] === 'work' && (! $in || ($out && $out->lte($in)))) {
            throw ValidationException::withMessages(['check_in' => 'Nhập giờ vào (và giờ ra sau giờ vào).']);
        }
        AttendanceEntry::query()->updateOrCreate(['user_id' => $userId, 'day' => $day], [
            'status' => $data['status'], 'check_in' => $in, 'check_out' => $out, 'source' => 'manual',
            'excuse_late' => (bool) ($data['excuse_late'] ?? false), 'note' => $data['note'] ?? null, 'edited_by' => Auth::id(),
        ]);
    }
}
