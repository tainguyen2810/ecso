<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

/**
 * When an order's sale counts as revenue (business decision, 2026-11-04):
 *  - marketplace orders (Shopee, TikTok Shop, Lazada, Tiki, ...) when the marketplace marks them COMPLETED, i.e. the
 *    return window is over, dated by orders.completed_at. Delivered but not yet completed orders are shown apart as
 *    "awaiting completion" (provisional);
 *  - the shop's own channels (manual orders, POS) and its WooCommerce website have no such step: at delivery
 *    (POS: at the sale), dated by orders.delivered_at.
 * An order with an open return request stays in revenue; the approved return is subtracted (ReturnValues).
 * Shipping charged to the buyer is not revenue from goods: it is reported on its own line.
 */
final class RevenuePolicy
{
    /** Order sources recognised at delivery. Every other source is a marketplace, recognised at completion. */
    public const AT_DELIVERY = [Order::SOURCE_MANUAL, WooCommerceConnector::MARKETPLACE];

    /** Statuses of an order whose goods reached the buyer and were not sent back. */
    public const STATUSES = [OrderStatus::DELIVERED, OrderStatus::COMPLETED, OrderStatus::RETURN_REQUESTED];

    /** SQL for the date an order's revenue is recognised (NULL while it is not). */
    public static function dateSql(string $orders = 'orders'): string
    {
        $sources = "'".implode("','", self::AT_DELIVERY)."'";

        return "(CASE WHEN {$orders}.marketplace IN ({$sources}) THEN {$orders}.delivered_at ELSE {$orders}.completed_at END)";
    }

    /** Orders whose revenue is recognised, optionally within a period. */
    public static function recognized(Builder $query, string $orders = 'orders', ?CarbonInterface $from = null, ?CarbonInterface $to = null): Builder
    {
        $query->whereIn("{$orders}.status", self::STATUSES);

        return $from && $to
            ? $query->whereRaw(self::dateSql($orders).' BETWEEN ? AND ?', [$from, $to])
            : $query->whereRaw(self::dateSql($orders).' IS NOT NULL');
    }

    /** Marketplace orders delivered in the period that the marketplace has not completed yet (provisional). */
    public static function awaitingCompletion(Builder $query, string $orders, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereIn("{$orders}.status", self::STATUSES)->whereNotIn("{$orders}.marketplace", self::AT_DELIVERY)
            ->whereNull("{$orders}.completed_at")->whereBetween("{$orders}.delivered_at", [$from, $to]);
    }
}
