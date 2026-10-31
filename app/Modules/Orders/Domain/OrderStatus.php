<?php

namespace App\Modules\Orders\Domain;

/**
 * Internal order statuses, allowed transitions and stock policy (ORDER-LIFECYCLE.md §1–2, INVENTORY-ENGINE §5.1).
 */
final class OrderStatus
{
    public const UNPAID = 'UNPAID';

    public const PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';

    public const READY_TO_PACK = 'READY_TO_PACK';

    public const PACKED = 'PACKED';

    public const HANDED_OVER = 'HANDED_OVER';

    public const IN_TRANSIT = 'IN_TRANSIT';

    public const DELIVERED = 'DELIVERED';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    public const FAILED_DELIVERY = 'FAILED_DELIVERY';

    public const RETURN_REQUESTED = 'RETURN_REQUESTED';

    public const RETURNED = 'RETURNED';

    /** A raw status we cannot map yet: reservations are kept as they are and the order is flagged. */
    public const UNKNOWN_STATUS = 'UNKNOWN_STATUS';

    public const RESERVE = 'reserve';

    public const CONSUME = 'consume';

    public const RELEASE = 'release';

    public const NONE = 'none';

    public const ALL = [
        self::UNPAID, self::PENDING_CONFIRMATION, self::READY_TO_PACK, self::PACKED, self::HANDED_OVER,
        self::IN_TRANSIT, self::DELIVERED, self::COMPLETED, self::CANCELLED, self::FAILED_DELIVERY,
        self::RETURN_REQUESTED, self::RETURNED, self::UNKNOWN_STATUS,
    ];

    private const TRANSITIONS = [
        self::UNPAID => [self::PENDING_CONFIRMATION, self::READY_TO_PACK, self::CANCELLED],
        self::PENDING_CONFIRMATION => [self::READY_TO_PACK, self::CANCELLED],
        self::READY_TO_PACK => [self::PACKED, self::CANCELLED],
        self::PACKED => [self::HANDED_OVER, self::CANCELLED],
        self::HANDED_OVER => [self::IN_TRANSIT, self::DELIVERED, self::FAILED_DELIVERY, self::CANCELLED],
        self::IN_TRANSIT => [self::DELIVERED, self::FAILED_DELIVERY],
        self::DELIVERED => [self::COMPLETED, self::RETURN_REQUESTED],
        self::COMPLETED => [self::RETURN_REQUESTED],
        self::FAILED_DELIVERY => [self::RETURNED],
        self::RETURN_REQUESTED => [self::RETURNED, self::COMPLETED],
        self::CANCELLED => [],
        self::RETURNED => [],
    ];

    /** Statuses in which the goods have left the warehouse. */
    public const SHIPPED = [
        self::HANDED_OVER, self::IN_TRANSIT, self::DELIVERED, self::COMPLETED, self::FAILED_DELIVERY,
        self::RETURN_REQUESTED, self::RETURNED,
    ];

    /** Statuses that still need work before handover (overdue checks apply). */
    public const OPEN = [self::UNPAID, self::PENDING_CONFIRMATION, self::READY_TO_PACK, self::PACKED];

    /** UI tabs => statuses (ORDER-LIFECYCLE §7). */
    public const TABS = [
        'new' => [self::UNPAID],
        'to_confirm' => [self::PENDING_CONFIRMATION],
        'to_pack' => [self::READY_TO_PACK],
        'to_handover' => [self::PACKED],
        'shipping' => [self::HANDED_OVER, self::IN_TRANSIT],
        'done' => [self::DELIVERED, self::COMPLETED],
        'cancelled' => [self::CANCELLED],
        'returns' => [self::FAILED_DELIVERY, self::RETURN_REQUESTED, self::RETURNED],
        'unknown' => [self::UNKNOWN_STATUS],
    ];

    public static function policy(string $status): string
    {
        return match (true) {
            in_array($status, self::OPEN, true) => self::RESERVE,
            in_array($status, self::SHIPPED, true) => self::CONSUME,
            $status === self::CANCELLED => self::RELEASE,
            default => self::NONE,
        };
    }

    /**
     * Whether the order may move from $from to $to, directly or by skipping intermediate steps
     * (events can arrive late or be lost; the API snapshot is the truth). Backwards moves are refused.
     */
    public static function canMove(?string $from, string $to): bool
    {
        if ($from === null || $from === $to || $from === self::UNKNOWN_STATUS || $to === self::UNKNOWN_STATUS) {
            return true;
        }

        $seen = [$from => true];
        $queue = [$from];
        while ($queue !== []) {
            foreach (self::TRANSITIONS[array_shift($queue)] ?? [] as $next) {
                if ($next === $to) {
                    return true;
                }
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    public static function isShipped(string $status): bool
    {
        return in_array($status, self::SHIPPED, true);
    }
}
