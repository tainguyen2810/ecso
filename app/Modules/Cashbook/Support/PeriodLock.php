<?php

namespace App\Modules\Cashbook\Support;

use App\Modules\Cashbook\Models\CashClosing;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * The closed period of the books: every money row (voucher, transfer, order payment or refund, supplier payment)
 * dated on or before the last closed day, in the business's time zone, can no longer be recorded, voided or
 * backdated. A mistake found later is fixed with a new voucher dated today.
 */
class PeriodLock
{
    /** The active closing, if the books are closed. */
    public static function current(): ?CashClosing
    {
        return CashClosing::query()->whereNull('reopened_at')->orderByDesc('closed_until')->orderByDesc('id')->first();
    }

    /** The last closed day, or null. */
    public static function closedUntil(): ?CarbonImmutable
    {
        $closing = self::current();

        return $closing ? ReportRange::date($closing->closed_until->toDateString()) : null;
    }

    /** Whether a moment falls in the closed period. */
    public static function isClosed(DateTimeInterface $at): bool
    {
        $until = self::closedUntil();

        return $until !== null && CarbonImmutable::instance($at)->lte($until->endOfDay());
    }

    /**
     * @param  string  $field  the request field the error belongs to
     * @param  string  $action  what was refused, e.g. "ghi nhận" or "hủy"
     */
    public static function assertOpen(DateTimeInterface $at, string $field = 'paid_at', string $action = 'ghi nhận'): void
    {
        if (self::isClosed($at)) {
            $until = self::closedUntil()?->format('d/m/Y');
            $hint = $action === 'hủy' ? ' Lập phiếu điều chỉnh ngày hôm nay thay vì hủy, hoặc nhờ người có quyền mở khóa sổ.' : ' Chọn ngày sau ngày khóa sổ.';

            throw ValidationException::withMessages([$field => "Sổ đã khóa đến hết ngày {$until}, không {$action} khoản tiền của kỳ này.{$hint}"]);
        }
    }
}
