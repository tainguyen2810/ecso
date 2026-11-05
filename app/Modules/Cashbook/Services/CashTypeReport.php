<?php

namespace App\Modules\Cashbook\Services;

use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;

/**
 * Money in and out of the business by kind (voucher type, customer payments, supplier payments...) month by month,
 * read from CashFlows like the cash book, so both always agree. Transfers between funds are left out: they are
 * neither money in nor money out. Money out is negative.
 */
class CashTypeReport
{
    /** Labels of the movements that are not vouchers. */
    public const KINDS = [
        'payment' => 'Khách trả tiền đơn hàng',
        'refund' => 'Hoàn tiền cho khách',
        'supplier' => 'Trả nhà cung cấp',
        'supplier_refund' => 'Nhà cung cấp hoàn tiền',
        'voucher_reversal' => 'Ghi đảo phiếu hủy ở kỳ đã khóa',
    ];

    public function __construct(private readonly CashFlows $flows) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $from, CarbonImmutable $to, string $by = 'type'): array
    {
        $month = 'DATE_FORMAT('.ReportRange::day('paid_at', $from).", '%Y-%m')";
        $grouped = (clone $this->flows->query())->whereBetween('paid_at', [$from, $to])
            ->groupByRaw("{$month}, type, label, amount >= 0")
            ->selectRaw("{$month} as month, type, label, amount >= 0 as incoming, COUNT(*) as entries, SUM(amount) as total")
            ->get();

        $months = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        if ($by === 'month') {
            $rows = [];
            foreach ($months as $m) {
                $in = $grouped->where('month', $m)->where('incoming', 1)->sum(fn ($r) => (float) $r->total);
                $out = -$grouped->where('month', $m)->where('incoming', 0)->sum(fn ($r) => (float) $r->total);
                $rows[] = ['label' => $m, 'entries' => (int) $grouped->where('month', $m)->sum('entries'), 'in' => round($in, 2), 'out' => round($out, 2), 'net' => round($in - $out, 2)];
            }
            $totals = [];
            foreach (['entries', 'in', 'out', 'net'] as $k) {
                $totals[$k] = round(array_sum(array_column($rows, $k)), 2);
            }

            return ['range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'by' => $by, 'months' => $months, 'rows' => $rows, 'totals' => $totals];
        }

        $rows = [];
        foreach ($grouped as $r) {
            $direction = (int) $r->incoming === 1 ? 'in' : 'out';
            $label = $r->type === 'voucher' ? (string) $r->label : (self::KINDS[$r->type] ?? (string) $r->type);
            $key = $direction.'|'.$label;
            $rows[$key] ??= ['label' => $label, 'direction' => $direction, 'entries' => 0, 'total' => 0.0] + array_fill_keys(array_map(fn ($m) => "m{$m}", $months), 0.0);
            $rows[$key]['entries'] += (int) $r->entries;
            $rows[$key]['total'] = round($rows[$key]['total'] + (float) $r->total, 2);
            $rows[$key]["m{$r->month}"] = round(($rows[$key]["m{$r->month}"] ?? 0) + (float) $r->total, 2);
        }
        // Money in first, then money out, each by size.
        $rows = collect($rows)->sortBy([['direction', 'asc'], fn ($a, $b) => abs($b['total']) <=> abs($a['total'])])->values()->all();

        $totals = ['entries' => array_sum(array_column($rows, 'entries'))];
        foreach (['total', ...array_map(fn ($m) => "m{$m}", $months)] as $k) {
            $totals[$k] = round(array_sum(array_column($rows, $k)), 2);
        }
        $totals['in'] = round(array_sum(array_column(array_filter($rows, fn ($r) => $r['direction'] === 'in'), 'total')), 2);
        $totals['out'] = round(-array_sum(array_column(array_filter($rows, fn ($r) => $r['direction'] === 'out'), 'total')), 2);

        return ['range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'by' => $by, 'months' => $months, 'rows' => $rows, 'totals' => $totals];
    }
}
