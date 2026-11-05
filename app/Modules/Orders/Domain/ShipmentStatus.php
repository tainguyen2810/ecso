<?php

namespace App\Modules\Orders\Domain;

/**
 * Status of one parcel at its carrier (vận đơn), separate from the order's status: an order can have several parcels,
 * and a carrier reports steps the order does not have (picked up, out for delivery, redelivering, returning).
 * Moves only forward along TRANSITIONS; an older or backward report is kept in the history without changing it.
 */
final class ShipmentStatus
{
    public const PENDING = 'pending';

    public const AWAITING_PICKUP = 'awaiting_pickup';

    public const PICKED_UP = 'picked_up';

    public const IN_TRANSIT = 'in_transit';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const REDELIVERING = 'redelivering';

    public const RETURNING = 'returning';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::PENDING => 'Chờ tạo vận đơn',
        self::AWAITING_PICKUP => 'Chờ lấy hàng',
        self::PICKED_UP => 'Đã lấy hàng',
        self::IN_TRANSIT => 'Đang vận chuyển',
        self::OUT_FOR_DELIVERY => 'Đang giao',
        self::DELIVERED => 'Giao thành công',
        self::FAILED => 'Giao thất bại',
        self::REDELIVERING => 'Giao lại',
        self::RETURNING => 'Đang hoàn',
        self::RETURNED => 'Đã hoàn',
        self::CANCELLED => 'Hủy vận đơn',
    ];

    public const ALL = [
        self::PENDING, self::AWAITING_PICKUP, self::PICKED_UP, self::IN_TRANSIT, self::OUT_FOR_DELIVERY, self::DELIVERED,
        self::FAILED, self::REDELIVERING, self::RETURNING, self::RETURNED, self::CANCELLED,
    ];

    /** The parcel is with the carrier (or on its way back): it can no longer be cancelled, only returned. */
    public const WITH_CARRIER = [self::PICKED_UP, self::IN_TRANSIT, self::OUT_FOR_DELIVERY, self::FAILED, self::REDELIVERING];

    /** Nothing more happens to the parcel. */
    public const FINAL = [self::DELIVERED, self::RETURNED, self::CANCELLED];

    private const ON_THE_WAY = [self::IN_TRANSIT, self::OUT_FOR_DELIVERY, self::DELIVERED, self::FAILED, self::RETURNING];

    public const TRANSITIONS = [
        self::PENDING => [self::AWAITING_PICKUP, self::PICKED_UP, ...self::ON_THE_WAY, self::CANCELLED],
        self::AWAITING_PICKUP => [self::PICKED_UP, ...self::ON_THE_WAY, self::CANCELLED],
        self::PICKED_UP => self::ON_THE_WAY,
        self::IN_TRANSIT => [self::OUT_FOR_DELIVERY, self::DELIVERED, self::FAILED, self::RETURNING],
        self::OUT_FOR_DELIVERY => [self::DELIVERED, self::FAILED, self::RETURNING],
        self::FAILED => [self::REDELIVERING, self::DELIVERED, self::RETURNING, self::RETURNED],
        self::REDELIVERING => [self::IN_TRANSIT, self::OUT_FOR_DELIVERY, self::DELIVERED, self::FAILED, self::RETURNING],
        self::RETURNING => [self::RETURNED],
        self::DELIVERED => [],
        self::RETURNED => [],
        self::CANCELLED => [],
    ];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
