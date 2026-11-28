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
use Illuminate\Support\Facades\DB;

/**
 * Vận chuyển › Tổng quan: where the parcels are now, what needs the shop, the COD carriers still hold, and how each
 * carrier did with the parcels made in the chosen days (marketplace carriers included).
 */
class ShippingOverviewController extends Controller
{
    /** COD held this long after delivery, or a parcel coming back this long, is flagged. */
    public const SLOW_DAYS = ShipmentQuery::SLOW_DAYS;

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
        $columns = ['order_id', 'carrier', 'carrier_name', 'source', 'status', 'payer', 'fee_actual', 'fee_estimated', 'picked_up_at', 'delivered_at', 'cod_status', 'cod_amount', 'created_at'];
        $rows = $base()->toBase()->whereBetween('created_at', [$from->utc(), $to->utc()])->get($columns);
        // The same number of days just before, for "so với kỳ trước".
        $days = (int) round($from->startOfDay()->diffInDays($to->startOfDay())) + 1;
        $prevRows = $base()->toBase()->whereBetween('created_at', [$from->subDays($days)->utc(), $from->subSecond()->utc()])
            ->get(['source', 'status', 'payer', 'fee_actual', 'fee_estimated']);

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
            'summary' => $this->summary($rows, $money),
            'previous' => $this->summary($prevRows, $money),
            'channels' => $this->channels($rows),
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

    /** Results of the parcels made in a period: how many arrived or came back, and the fees the shop paid. */
    private function summary($rows, callable $money): array
    {
        $live = $rows->where('status', '!=', ShipmentStatus::CANCELLED);
        $finished = $live->filter(fn ($r) => in_array($r->status, [ShipmentStatus::DELIVERED, ShipmentStatus::RETURNED, ShipmentStatus::RETURNING], true))->count();
        $delivered = $live->where('status', ShipmentStatus::DELIVERED)->count();
        $returned = $live->filter(fn ($r) => in_array($r->status, [ShipmentStatus::RETURNED, ShipmentStatus::RETURNING], true))->count();
        $paid = $live->filter(fn ($r) => $r->source !== Shipment::SOURCE_MARKETPLACE && $r->payer !== 'buyer' && ($r->fee_actual ?? $r->fee_estimated) !== null);

        return [
            'count' => $live->count(),
            'finished' => $finished,
            'delivered' => $delivered,
            'returned' => $returned,
            'failed' => $live->where('status', ShipmentStatus::FAILED)->count(),
            'delivered_rate' => $finished ? round($delivered * 100 / $finished, 1) : null,
            'returned_rate' => $finished ? round($returned * 100 / $finished, 1) : null,
            'fee_total' => $money($paid->sum(fn ($r) => (float) ($r->fee_actual ?? $r->fee_estimated))),
            'fee_count' => $paid->count(),
            'buyer_paid' => $live->where('payer', 'buyer')->count(),
        ];
    }

    /** Parcels per sales channel: the shop (marketplace account) or the shop's own channel the order came from. */
    private function channels($rows): array
    {
        $live = $rows->where('status', '!=', ShipmentStatus::CANCELLED);
        $orderIds = $live->pluck('order_id')->filter()->unique()->values();
        if ($orderIds->isEmpty()) {
            return [];
        }
        $orders = collect();
        foreach ($orderIds->chunk(1000) as $chunk) {
            $orders = $orders->merge(DB::table('orders')
                ->leftJoin('marketplace_accounts', 'marketplace_accounts.id', '=', 'orders.marketplace_account_id')
                ->leftJoin('sales_channels', 'sales_channels.id', '=', 'orders.sales_channel_id')
                ->whereIn('orders.id', $chunk->all())
                ->selectRaw("orders.id, COALESCE(marketplace_accounts.shop_name, sales_channels.name, 'Khác') as label, COALESCE(marketplace_accounts.marketplace, sales_channels.type, orders.marketplace) as kind")
                ->get());
        }
        $byOrder = $orders->keyBy('id');

        return $live->groupBy(function ($r) use ($byOrder) {
            $o = $byOrder->get($r->order_id);

            return ($o->label ?? 'Khác')."\u{1F}".($o->kind ?? '');
        })->map(function ($group, $key) {
            [$label, $kind] = explode("\u{1F}", $key);

            return ['label' => $label, 'kind' => $kind !== '' ? $kind : null, 'count' => $group->count()];
        })->sortByDesc('count')->values()->all();
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
                    'fee_total' => $money($paid->filter(fn ($r) => $r->payer !== 'buyer')->sum(fn ($r) => (float) ($r->fee_actual ?? $r->fee_estimated))),
                    'cod_held' => $money($group->where('cod_status', Shipment::COD_COLLECTED)->sum(fn ($r) => (float) $r->cod_amount)),
                ];
            })->sortByDesc('count')->values()->all();
    }
}
