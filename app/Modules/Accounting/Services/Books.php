<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Support\HouseholdTax;
use App\Modules\Analytics\Services\BusinessReports;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kế toán, thuế: tổng quan thuế, sổ sách của hộ kinh doanh (mẫu theo Thông tư 88/2021: S1 doanh thu, S2 vật liệu
 * hàng hoá, S3 chi phí, S4 nghĩa vụ thuế) và tờ khai theo kỳ, lập từ dữ liệu sẵn có (đơn hàng, kho, phiếu chi).
 */
class Books
{
    public const BOOKS = [
        's1' => ['code' => 'S1-HKD', 'title' => 'Sổ chi tiết doanh thu bán hàng hoá, dịch vụ'],
        's2' => ['code' => 'S2-HKD', 'title' => 'Sổ chi tiết vật liệu, dụng cụ, sản phẩm, hàng hoá'],
        's3' => ['code' => 'S3-HKD', 'title' => 'Sổ chi phí sản xuất, kinh doanh'],
        's4' => ['code' => 'S4-HKD', 'title' => 'Sổ theo dõi tình hình thực hiện nghĩa vụ thuế với NSNN'],
    ];

    public function __construct(private readonly TaxFigures $figures, private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function overview(int $year): array
    {
        $tax = HouseholdTax::current();
        $zone = ReportRange::timezone();
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $zone);
        $now = ReportRange::now();
        $end = $year < $now->year ? $start->endOfYear() : $now->endOfDay();
        $entries = $year > $now->year ? [] : $this->figures->entries($start, $end);
        $sum = $this->figures->summarize($entries);

        $channels = [];
        foreach ($entries as $e) {
            $key = str_starts_with($e['channel'], 'return:') ? substr($e['channel'], 7) : $e['channel'];
            $channels[$key] ??= ['label' => str_replace('Khách trả hàng · ', '', $e['label']), 'kind' => $e['kind'], 'revenue' => 0.0, 'tax' => 0.0];
            $r = $tax->rates($e['group']);
            $channels[$key]['revenue'] += $e['amount'];
            $channels[$key]['tax'] += $e['amount'] * ($r['vat'] + $r['pit']) / 100;
        }
        $channels = array_values(array_map(fn ($c) => [...$c, 'revenue' => round($c['revenue']), 'tax' => round(max(0, $c['tax']))], $channels));
        usort($channels, fn ($a, $b) => [$a['kind'], -$a['revenue']] <=> [$b['kind'], -$b['revenue']]);

        // Rough forecast: the day the year's revenue reaches the threshold at the pace so far.
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $perDay = $sum['total'] / $days;
        $reach = null;
        if ($year === $now->year && $sum['total'] < $tax->threshold() && $perDay > 0) {
            $at = $now->addDays((int) ceil(($tax->threshold() - $sum['total']) / $perDay));
            $reach = $at->year === $year ? $at->toDateString() : null;
        }

        $period = $year === $now->year ? $tax->periodOf($now) : $tax->periodsOf($year)[count($tax->periodsOf($year)) - 1];
        $previous = $tax->periodOf(HouseholdTax::bounds($period)[0]->subDay());

        return [
            'year' => $year,
            'settings' => $this->settingsView(),
            'revenue' => $sum['total'],
            'threshold' => $tax->threshold(),
            'reach_on' => $reach,
            'channels' => $channels,
            'self' => $sum['self'], 'market' => $sum['market'], 'tax' => $sum['tax'], 'withheld' => $sum['withheld'],
            'current' => [...$this->declaration($period, false), 'taxpayer' => $this->taxpayer()],
            'todo' => [
                'declare' => ['period' => $previous, 'label' => HouseholdTax::bounds($previous)[2], 'deadline' => HouseholdTax::deadline($previous)->toDateString()],
                'invoices_review' => PurchaseInvoice::query()->where('status', 'open')->whereRaw('JSON_LENGTH(issues) > 0')->count(),
                'invoices_open' => PurchaseInvoice::query()->where('status', 'open')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function declaration(string $period, bool $withSources = true): array
    {
        [$from, $to, $label] = HouseholdTax::bounds($period);
        $entries = $this->figures->entries($from, $to);
        $sum = $this->figures->summarize($entries);
        $out = [
            'period' => $period,
            'label' => $label,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'deadline' => HouseholdTax::deadline($period)->toDateString(),
            'groups' => array_values(array_filter($sum['groups'], fn ($g) => $g['self'] != 0 || $g['market'] != 0)),
            'self' => $sum['self'], 'market' => $sum['market'], 'vat' => $sum['vat'], 'pit' => $sum['pit'], 'tax' => $sum['tax'], 'withheld' => $sum['withheld'],
        ];
        if ($withSources) {
            $out['taxpayer'] = $this->taxpayer();
            $out['returns'] = round(-array_sum(array_map(fn ($e) => str_starts_with($e['channel'], 'return:') && $e['kind'] === 'self' ? $e['amount'] : 0, $entries)));
            $numbers = [];
            foreach ($entries as $e) {
                if ($e['kind'] === 'self' && $e['amount'] > 0) {
                    foreach ($e['orders'] as $n) {
                        $numbers[$n] = true;
                    }
                }
            }
            $out['orders'] = count($numbers);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function book(string $book, string $month): array
    {
        [$from, $to, $label] = HouseholdTax::bounds($month);
        $head = ['book' => $book, ...self::BOOKS[$book], 'period' => $month, 'label' => $label, 'taxpayer' => $this->taxpayer()];

        return $head + match ($book) {
            's1' => $this->s1($from, $to),
            's2' => $this->s2($from, $to),
            's3' => $this->s3($from, $to),
            default => $this->s4((int) $from->year),
        };
    }

    /** @return array<string, mixed> */
    private function s1(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tax = HouseholdTax::current();
        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        foreach ($this->figures->entries($from, $to) as $e) {
            $key = $e['day'].'|'.$e['channel'];
            $r = $tax->rates($e['group']);
            $rows[$key] ??= ['day' => $e['day'], 'label' => $e['label'], 'kind' => $e['kind'], 'orders' => [], 'self' => 0.0, 'market' => 0.0, 'withheld' => 0.0];
            $rows[$key][$e['kind']] += $e['amount'];
            if ($e['kind'] === 'market') {
                $rows[$key]['withheld'] += $e['amount'] * ($r['vat'] + $r['pit']) / 100;
            }
            $rows[$key]['orders'] = array_values(array_unique([...$rows[$key]['orders'], ...$e['orders']]));
        }
        $rows = array_values(array_map(fn ($r) => [
            ...$r, 'self' => round($r['self']), 'market' => round($r['market']), 'withheld' => round($r['withheld']),
            'count' => count($r['orders']), 'orders' => array_slice($r['orders'], 0, 20),
        ], $rows));

        return [
            'columns' => ['day', 'label', 'documents', 'self', 'market', 'withheld'],
            'rows' => $rows,
            'totals' => ['self' => array_sum(array_column($rows, 'self')), 'market' => array_sum(array_column($rows, 'market')), 'withheld' => array_sum(array_column($rows, 'withheld'))],
        ];
    }

    /** @return array<string, mixed> */
    private function s2(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ledger = app(BusinessReports::class)->stockLedger($from, $to);
        $rows = array_map(fn ($r) => [
            'sku' => $r['variant']['sku'], 'name' => $r['variant']['name'], 'opening' => $r['opening'], 'in' => $r['in'], 'out' => $r['out'],
            'closing' => $r['closing'], 'unit_cost' => $r['unit_cost'], 'closing_value' => $r['closing_value'],
        ], $ledger['rows']);

        return [
            'rows' => $rows,
            'totals' => ['opening' => $ledger['totals']['opening'], 'in' => $ledger['totals']['in'], 'out' => $ledger['totals']['out'], 'closing' => $ledger['totals']['closing'], 'closing_value' => round($ledger['totals']['closing_value'])],
            'truncated' => $ledger['truncated'],
        ];
    }

    /** @return array<string, mixed> */
    private function s3(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $vouchers = DB::table('cash_vouchers as cv')->join('cash_voucher_types as t', 't.id', '=', 'cv.cash_voucher_type_id')
            ->where('cv.tenant_id', $this->tenant())->where('cv.direction', 'payment')->where('cv.counts_in_pnl', true)
            ->whereNull('cv.voided_at')->where('cv.status', 'completed')->whereBetween('cv.paid_at', [$from, $to])
            ->orderBy('cv.paid_at')->limit(2000)
            ->get(['cv.ulid', 'cv.number', 'cv.paid_at', 'cv.amount', 'cv.party_name', 'cv.note', 't.name as type']);
        $rows = $vouchers->map(fn ($v) => [
            'day' => CarbonImmutable::parse($v->paid_at)->setTimezone(ReportRange::timezone())->toDateString(),
            'number' => $v->number, 'id' => $v->ulid, 'type' => $v->type,
            'label' => trim($v->type.($v->note ? ' · '.$v->note : '').($v->party_name ? ' · '.$v->party_name : '')),
            'amount' => (float) $v->amount,
        ])->all();
        $byType = [];
        foreach ($rows as $r) {
            $byType[$r['type']] = ($byType[$r['type']] ?? 0) + $r['amount'];
        }
        arsort($byType);

        return [
            'rows' => $rows,
            'by_type' => array_map(fn ($k, $v) => ['type' => $k, 'amount' => $v], array_keys($byType), $byType),
            'totals' => ['amount' => array_sum(array_column($rows, 'amount'))],
        ];
    }

    /** @return array<string, mixed> */
    private function s4(int $year): array
    {
        $tax = HouseholdTax::current();
        $now = ReportRange::now();
        $rows = [];
        foreach ($tax->periodsOf($year) as $p) {
            [$from] = HouseholdTax::bounds($p);
            if ($from->greaterThan($now)) {
                break;
            }
            $d = $this->declaration($p, false);
            $rows[] = ['period' => $p, 'label' => $d['label'], 'self' => $d['self'], 'vat' => $d['vat'], 'pit' => $d['pit'], 'tax' => $d['tax'], 'market' => $d['market'], 'withheld' => $d['withheld'], 'deadline' => $d['deadline']];
        }

        return [
            'rows' => $rows,
            'totals' => array_map(fn ($k) => array_sum(array_column($rows, $k)), array_combine($k = ['self', 'vat', 'pit', 'tax', 'market', 'withheld'], $k)),
        ];
    }

    /** @return array<string, mixed> */
    public function settingsView(): array
    {
        $tax = HouseholdTax::current();

        return [
            'period' => $tax->period(),
            'threshold' => $tax->threshold(),
            'taxpayer_name' => $tax->values['taxpayer_name'],
            'default_group' => $tax->defaultGroup(),
            'category_groups' => (object) $tax->categoryGroups(),
            'include_shipping' => $tax->includeShipping(),
            'rates' => array_map(fn ($g) => $tax->rates($g), array_combine(array_keys(HouseholdTax::GROUPS), array_keys(HouseholdTax::GROUPS))),
            'groups' => HouseholdTax::GROUPS,
        ];
    }

    /** @return array<string, mixed> */
    private function taxpayer(): array
    {
        $tenant = $this->context->tenant();
        $s = TenantSettings::current();
        $address = collect([$s->get('store.address'), $s->get('store.ward'), $s->get('store.province')])->filter()->implode(', ');

        return [
            'name' => (string) (HouseholdTax::current()->values['taxpayer_name'] ?? '') ?: null,
            'store' => $tenant->name,
            'tax_code' => $s->get('store.tax_code'),
            'address' => $address ?: null,
            'phone' => $s->get('store.phone'),
        ];
    }

    private function tenant(): int
    {
        return $this->context->tenant()->id;
    }
}
