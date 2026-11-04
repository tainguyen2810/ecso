<?php

namespace App\Modules\Analytics\Support;

/**
 * Cost of goods and profit are for members with the finance.profit permission ("xem giá vốn và lợi nhuận"). For the
 * others these fields are taken out of a report's rows and totals before it leaves the server, so neither the page
 * nor its CSV export can show them.
 */
final class ProfitFields
{
    public const KEYS = ['cogs', 'gross_profit', 'margin', 'missing_cost_lines', 'cost_snapshot', 'unit_cost'];

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function strip(array $report, bool $allowed): array
    {
        if ($allowed) {
            return [...$report, 'profit_hidden' => false];
        }
        $drop = fn (array $row) => array_diff_key($row, array_flip(self::KEYS));
        if (isset($report['rows']) && is_array($report['rows'])) {
            $report['rows'] = array_map(fn ($r) => is_array($r) ? $drop($r) : $r, $report['rows']);
        }
        if (isset($report['totals']) && is_array($report['totals'])) {
            $report['totals'] = $drop($report['totals']);
        }

        return [...$report, 'profit_hidden' => true];
    }
}
