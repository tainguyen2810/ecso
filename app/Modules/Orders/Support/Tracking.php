<?php

namespace App\Modules\Orders\Support;

use App\Modules\Cashbook\Models\CodReconciliation;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tracking numbers (mã vận đơn) and carriers of orders.
 *
 * A tracking number identifies one parcel at one carrier: two live orders of the same business must not share it,
 * or a carrier's COD statement would pay the money of one order into the other.
 */
final class Tracking
{
    public static function normalize(?string $tracking): ?string
    {
        $tracking = trim(preg_replace('/\s+/u', ' ', (string) $tracking) ?? '');

        return $tracking === '' ? null : mb_substr($tracking, 0, 100);
    }

    /** Refuses a tracking number already used by another order of this business that is not cancelled. */
    public static function assertUnique(Order $order, ?string $tracking): void
    {
        if ($tracking === null || $tracking === '') {
            return;
        }
        $other = Order::query()->where('tracking_number', $tracking)->where('status', '!=', OrderStatus::CANCELLED)
            ->when($order->exists, fn ($q) => $q->whereKeyNot($order->id))
            ->value('external_order_id');
        if ($other !== null) {
            throw ValidationException::withMessages(['tracking_number' => "Mã vận đơn {$tracking} đã dùng cho đơn {$other}. Kiểm tra lại mã (mỗi kiện hàng một mã)."]);
        }
    }

    /**
     * The carrier key of COD statements (ghn, viettelpost, spx, jt) for what an order records as its carrier, which is
     * free text ("GHN", "J&T Express", "viettel post"...). Null when it is not one of them (own shipper, other).
     */
    public static function carrierKey(?string $carrier): ?string
    {
        $plain = Str::of((string) $carrier)->ascii()->lower()->replaceMatches('/[^a-z0-9&]/', '')->toString();
        if ($plain === '') {
            return null;
        }
        foreach (['ghn' => ['ghn', 'giaohangnhanh'], 'viettelpost' => ['viettelpost', 'vtp', 'viettel'], 'spx' => ['spx', 'shopeeexpress'], 'jt' => ['j&t', 'jt', 'jnt', 'jtexpress', 'j&texpress']] as $key => $names) {
            foreach ($names as $name) {
                if ($plain === $name || str_starts_with($plain, $name)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** Display name of a COD carrier key. */
    public static function carrierName(string $key): string
    {
        return CodReconciliation::CARRIERS[$key] ?? $key;
    }
}
