<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Tenancy\Support\TenantSettings;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;

/**
 * Thuế hộ kinh doanh theo tỷ lệ % trên doanh thu: nhóm ngành nghề (Thông tư 40/2021 phụ lục I) với tỷ lệ GTGT và
 * TNCN mặc định, kỳ khai (tháng hoặc quý) và hạn nộp. Tỷ lệ và ngưỡng nằm trong Cấu hình nên sửa được khi luật đổi.
 */
final class HouseholdTax
{
    public const GROUPS = [
        'goods' => 'Phân phối, cung cấp hàng hoá',
        'service' => 'Dịch vụ, xây dựng không bao thầu nguyên vật liệu',
        'production' => 'Sản xuất, vận tải, dịch vụ có gắn với hàng hoá, xây dựng có bao thầu nguyên vật liệu',
        'other' => 'Hoạt động kinh doanh khác',
    ];

    /** @param  array<string, mixed>  $values */
    private function __construct(public readonly array $values) {}

    public static function current(): self
    {
        return new self((array) TenantSettings::current()->get('household_tax'));
    }

    public function period(): string
    {
        return $this->values['period'] === 'month' ? 'month' : 'quarter';
    }

    public function threshold(): float
    {
        return (float) $this->values['threshold'];
    }

    public function defaultGroup(): string
    {
        return array_key_exists((string) $this->values['default_group'], self::GROUPS) ? (string) $this->values['default_group'] : 'goods';
    }

    /** @return array<string, string> category ulid => group */
    public function categoryGroups(): array
    {
        return array_filter((array) $this->values['category_groups'], fn ($g) => is_string($g) && array_key_exists($g, self::GROUPS));
    }

    /** @return array{vat: float, pit: float} in % */
    public function rates(string $group): array
    {
        $r = (array) ($this->values['rates'][$group] ?? []);

        return ['vat' => (float) ($r['vat'] ?? 0), 'pit' => (float) ($r['pit'] ?? 0)];
    }

    public function includeShipping(): bool
    {
        return (bool) $this->values['include_shipping'];
    }

    /**
     * "2026-11" or "2026-Q4" (local time) => [from, to, label].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    public static function bounds(string $period): array
    {
        $zone = ReportRange::timezone();
        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m) === 1) {
            $from = CarbonImmutable::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1, 0, 0, 0, $zone);

            return [$from, $from->addMonths(2)->endOfMonth(), "Quý {$m[2]} năm {$m[1]}"];
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $from = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, $zone);

            return [$from, $from->endOfMonth(), 'Tháng '.(int) $m[2].' năm '.$m[1]];
        }
        abort(422, 'Kỳ khai không đúng.');
    }

    /** The period that contains a date, in the business's chosen kind. */
    public function periodOf(CarbonImmutable $date): string
    {
        return $this->period() === 'month' ? $date->format('Y-m') : $date->year.'-Q'.$date->quarter;
    }

    /** @return array<int, string> every period of a year */
    public function periodsOf(int $year): array
    {
        return $this->period() === 'month'
            ? array_map(fn ($m) => sprintf('%d-%02d', $year, $m), range(1, 12))
            : array_map(fn ($q) => "{$year}-Q{$q}", range(1, 4));
    }

    /** Hạn nộp: tháng → ngày 20 tháng sau; quý → ngày cuối tháng đầu quý sau. */
    public static function deadline(string $period): CarbonImmutable
    {
        [, $to] = self::bounds($period);

        return str_contains($period, 'Q') ? $to->addDay()->endOfMonth()->startOfDay() : $to->addDay()->setDay(20)->startOfDay();
    }
}
