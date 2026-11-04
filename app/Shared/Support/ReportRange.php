<?php

namespace App\Shared\Support;

use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * Report periods in the business's own time zone (tenants.timezone, Asia/Ho_Chi_Minh by default).
 *
 * Timestamps are stored in UTC. A report day is a day on the shop's clock, so "1/11" runs from 1/11 00:00 to 23:59
 * local time. The bounds returned here are CarbonImmutable values in the local zone; the database connection
 * converts every bound date to UTC (see LocalizedBindings), so they can go straight into where clauses. Grouping by
 * day uses day() so a row is put on its local date, not its UTC date.
 */
final class ReportRange
{
    public const DEFAULT_TIMEZONE = 'Asia/Ho_Chi_Minh';

    public static function timezone(): string
    {
        $context = app(TenantContext::class);
        $zone = $context->has() ? $context->tenant()->timezone : null;

        return $zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : self::DEFAULT_TIMEZONE;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** A date (Y-m-d) read as a day on the shop's clock. */
    public static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay();
    }

    /**
     * from / to query parameters as local start and end of day. Defaults to this month up to today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function fromRequest(Request $request, int $maxDays = 366): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($data['from']) ? self::date($data['from']) : self::now()->startOfMonth();
        $to = isset($data['to']) ? self::date($data['to'])->endOfDay() : self::now()->endOfDay();
        abort_if($from->diffInDays($to) > $maxDays, 422, 'Khoảng thời gian tối đa 1 năm.');

        return [$from, $to];
    }

    /** SQL for the local calendar date of a UTC timestamp column, e.g. DATE(orders.placed_at) shifted by +7h. */
    public static function day(string $column, ?CarbonInterface $at = null): string
    {
        $minutes = (int) ($at ?? self::now())->utcOffset();

        return $minutes === 0 ? "DATE({$column})" : "DATE(DATE_ADD({$column}, INTERVAL {$minutes} MINUTE))";
    }
}
