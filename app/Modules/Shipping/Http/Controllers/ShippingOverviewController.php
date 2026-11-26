<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Services\ShipmentQuery;
use App\Modules\Shipping\Services\CarrierShipmentService;
use App\Shared\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vận chuyển › Tổng quan: where the parcels are now, what needs the shop, the COD carriers still hold, and how each
 * carrier did with the parcels made in the chosen days (marketplace carriers included).
 */
class ShippingOverviewController extends Controller
{
    /** COD held this long after delivery, or a parcel coming back this long, is flagged. */
    public const SLOW_DAYS = 7;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        [$from, $to] = ReportRange::fromRequest($request, 92);
        $base = fn () => ShipmentQuery::visible();
        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        $byStatus = $base()->toBase()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $count = fn (string $tab) => (int) collect(ShipmentQuery::TABS[$tab])->sum(fn ($s) => $byStatus[$s] ?? 0);
        $held = $base()->toBase()->where('cod_status', Shipment::COD_COLLECTED)->selectRaw('COUNT(*) as n, COALESCE(SUM(cod_amount), 0) as amount')->first();
        $heldSlow = $base()->toBase()->where('cod_status', Shipment::COD_COLLECTED)->where('delivered_at', '<', now()->subDays(self::SLOW_DAYS))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(cod_amount), 0) as amount')->first();
        $gap = ShipmentQuery::feeGap($base())->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(fee_actual - fee_estimated), 0) as amount')->first();

        // Parcels made in the chosen days: one row each, grouped here (a few thousand at most for 92 days).
        $rows = $base()->toBase()->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->get(['carrier', 'carrier_name', 'source', 'status', 'payer', 'fee_actual', 'fee_estimated', 'picked_up_at', 'delivered_at', 'cod_status', 'cod_amount', 'created_at']);

        return response()->json(['data' => [
            'now' => [
                'to_push' => CarrierShipmentService::queueQuery()->count(),
                'awaiting' => $count('awaiting'),
                'overdue_pickup' => $base()->whereIn('status', ShipmentQuery::TABS['awaiting'])->where('ship_by_at', '<', now())->count(),
                'delivering' => $count('delivering'),
                'late' => ShipmentQuery::late($base())->count(),
                'late_days' => ShipmentQuery::LATE_DAYS,
                'failed' => $count('failed'),
                'returning' => $count('returning'),
                'returning_slow' => $base()->where('status', ShipmentStatus::RETURNING)->where('last_event_at', '<', now()->subDays(self::SLOW_DAYS))->count(),
                'cod_held' => ['count' => (int) $held->n, 'amount' => $money($held->amount)],
                'cod_held_slow' => ['count' => (int) $heldSlow->n, 'amount' => $money($heldSlow->amount)],
                'fee_gap' => ['count' => (int) $gap->n, 'amount' => $money($gap->amount)],
                'slow_days' => self::SLOW_DAYS,
            ],
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total' => $rows->count(),
            'days' => $this->days($rows, $from, $to),
            'carriers' => $this->carriers($rows, $money),
        ]]);
    }

    /** Parcels made each day: delivered, failed or coming back, still on the way. */
    private function days($rows, $from, $to): array
    {
        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'delivered' => 0, 'problem' => 0, 'open' => 0];
        }
        foreach ($rows as $r) {
            $key = ReportRange::now()->setTimestamp(strtotime($r->created_at.' UTC'))->toDateString();
            if (! isset($days[$key]) || $r->status === ShipmentStatus::CANCELLED) {
                continue;
            }
            $days[$key][$this->outcome($r->status)]++;
        }

        return array_values($days);
    }

    private function outcome(string $status): string
    {
        return match ($status) {
            ShipmentStatus::DELIVERED => 'delivered',
            ShipmentStatus::FAILED, ShipmentStatus::RETURNING, ShipmentStatus::RETURNED => 'problem',
            default => 'open',
        };
    }

    /** One line per carrier: how many, how many arrived or came back, days on the road, fee per parcel, COD held. */
    private function carriers($rows, callable $money): array
    {
        return $rows->where('status', '!=', ShipmentStatus::CANCELLED)->groupBy(fn ($r) => $r->carrier ?? 'other')
            ->map(function ($group, $carrier) use ($money) {
                $finished = $group->filter(fn ($r) => in_array($r->status, [ShipmentStatus::DELIVERED, ShipmentStatus::RETURNED, ShipmentStatus::RETURNING], true));
                $delivered = $group->where('status', ShipmentStatus::DELIVERED);
                $returned = $group->filter(fn ($r) => in_array($r->status, [ShipmentStatus::RETURNED, ShipmentStatus::RETURNING], true));
                $roadDays = $delivered->filter(fn ($r) => $r->picked_up_at && $r->delivered_at)
                    ->map(fn ($r) => max(0, strtotime($r->delivered_at) - strtotime($r->picked_up_at)) / 86400);
                // Marketplace parcels: the marketplace pays the carrier, so they have no fee of the shop's.
                $paid = $group->filter(fn ($r) => $r->source !== Shipment::SOURCE_MARKETPLACE && ($r->fee_actual ?? $r->fee_estimated) !== null);

                return [
                    'carrier' => $carrier,
                    'carrier_name' => $group->pluck('carrier_name')->filter()->first(),
                    'marketplace' => $group->every(fn ($r) => $r->source === Shipment::SOURCE_MARKETPLACE),
                    'count' => $group->count(),
                    'delivered_rate' => $finished->count() ? round($delivered->count() * 100 / $finished->count(), 1) : null,
                    'returned_rate' => $finished->count() ? round($returned->count() * 100 / $finished->count(), 1) : null,
                    'road_days' => $roadDays->count() ? round($roadDays->avg(), 1) : null,
                    'avg_fee' => $paid->count() ? $money($paid->avg(fn ($r) => (float) ($r->fee_actual ?? $r->fee_estimated))) : null,
                    'cod_held' => $money($group->where('cod_status', Shipment::COD_COLLECTED)->sum(fn ($r) => (float) $r->cod_amount)),
                ];
            })->sortByDesc('count')->values()->all();
    }
}
