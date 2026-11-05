<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Http\Resources\ShipmentResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Services\OrderQuery;
use App\Modules\Orders\Services\ShipmentService;
use App\Shared\Support\ReportRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Shipments (vận đơn): the "Vận chuyển & giao hàng" page, the order's shipment card, and what the shop does by hand. */
class ShipmentController extends Controller
{
    /** Tabs of the page: shipment statuses grouped the way the shop works on them. */
    public const TABS = [
        'awaiting' => [ShipmentStatus::PENDING, ShipmentStatus::AWAITING_PICKUP],
        'delivering' => [ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY, ShipmentStatus::REDELIVERING],
        'failed' => [ShipmentStatus::FAILED],
        'returning' => [ShipmentStatus::RETURNING],
        'delivered' => [ShipmentStatus::DELIVERED],
        'returned' => [ShipmentStatus::RETURNED],
        'cancelled' => [ShipmentStatus::CANCELLED],
    ];

    /** A parcel still on its way this many days after pickup is late. */
    public const LATE_DAYS = 5;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'status' => ['nullable', Rule::in(ShipmentStatus::ALL)],
            'carrier' => ['nullable', 'string', 'max:20'],
            'cod' => ['nullable', Rule::in(['with', 'without', Shipment::COD_PENDING, Shipment::COD_COLLECTED, Shipment::COD_REMITTED, Shipment::COD_RETURNED])],
            'warehouse_id' => ['nullable', 'string', 'max:26'],
            'channel' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'late' => ['nullable', 'boolean'],
            'order_id' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $this->visible()->with(['order.channel', 'warehouse'])
            ->when($filters['tab'] ?? null, fn ($q, $tab) => $q->whereIn('status', self::TABS[$tab]))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['carrier'] ?? null, fn ($q, $c) => $c === 'other' ? $q->whereNull('carrier') : $q->where('carrier', $c))
            ->when($filters['cod'] ?? null, fn ($q, $c) => match ($c) {
                'with' => $q->where('cod_amount', '>', 0),
                'without' => $q->where('cod_amount', '<=', 0),
                default => $q->where('cod_status', $c),
            })
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->whereIn('warehouse_id', Warehouse::query()->where('ulid', $id)->select('id')))
            ->when($filters['channel'] ?? null, fn ($q, $ch) => $q->whereHas('order', fn ($o) => $o->where('marketplace', $ch)))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('picked_up_at', '>=', ReportRange::date($d)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('picked_up_at', '<=', ReportRange::date($d)->endOfDay()->utc()))
            ->when($filters['late'] ?? false, fn ($q) => $this->late($q))
            ->when($filters['order_id'] ?? null, fn ($q, $id) => $q->whereHas('order', fn ($o) => $o->where('ulid', $id)))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('tracking_number', 'like', "%{$term}%")
                ->orWhereHas('order', fn ($o) => $o->where('external_order_id', 'like', "%{$term}%"))))
            ->orderByDesc('last_event_at')->orderByDesc('id')->paginate(30);
        ShipmentResource::rememberAreas($page->getCollection());

        return ShipmentResource::collection($page);
    }

    /** KPI: count per tab, late parcels, and the COD carriers hold (delivered, not paid to the shop yet) per carrier. */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $byStatus = $this->visible()->toBase()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return response()->json(['data' => [
            'tabs' => collect(self::TABS)->map(fn ($statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0))->put('all', (int) $byStatus->sum()),
            'by_status' => $byStatus,
            'late' => $this->late($this->visible())->count(),
            'late_days' => self::LATE_DAYS,
            'cod_held' => $this->visible()->toBase()->where('cod_status', Shipment::COD_COLLECTED)
                ->selectRaw('carrier, MAX(carrier_name) as carrier_name, SUM(cod_amount) as amount, COUNT(*) as n')->groupBy('carrier')->orderByDesc('amount')->get()
                ->map(fn ($r) => ['carrier' => $r->carrier, 'carrier_name' => $r->carrier_name, 'amount' => number_format((float) $r->amount, 2, '.', ''), 'count' => (int) $r->n])->values(),
        ]]);
    }

    public function show(Shipment $shipment): JsonResponse
    {
        $order = $shipment->order()->firstOrFail();
        $this->authorize('view', $order);
        $shipment->load(['order.channel', 'warehouse', 'events.actor:id,name']);
        ShipmentResource::rememberAreas(collect([$shipment]));

        return (new ShipmentResource($shipment))->additional(['data' => [
            'returns' => ReturnResource::collection(OrderReturn::query()->where('order_id', $order->id)->orderBy('id')->get()),
        ]])->response();
    }

    public function store(Request $request, Order $order, ShipmentService $service): JsonResponse
    {
        $this->authorize('view', $order);
        $this->authorize('update', $order);
        $data = $request->validate([
            'mode' => ['required', Rule::in([Shipment::SOURCE_MANUAL, Shipment::SOURCE_SELF])],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'packages' => ['nullable', 'integer', 'min:1', 'max:999'],
            'weight_g' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'fee_estimated' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'payer' => ['nullable', Rule::in(['shop', 'buyer'])],
        ]);
        $shipment = $service->create($order, $data);

        return (new ShipmentResource($shipment->load(['order.channel', 'warehouse', 'events'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Shipment $shipment, ShipmentService $service): ShipmentResource
    {
        $this->authorize('view', $shipment->order);
        $this->authorize('update', $shipment->order);
        $data = $request->validate([
            'carrier' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'packages' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'weight_g' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'fee_estimated' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);

        return new ShipmentResource($service->update($shipment, $data)->load(['order.channel', 'warehouse', 'events']));
    }

    public function action(Request $request, Shipment $shipment, string $action, ShipmentService $service): ShipmentResource
    {
        $this->authorize('view', $shipment->order);
        $this->authorize('update', $shipment->order);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new ShipmentResource($service->act($shipment, $action, $data['reason'] ?? null)->load(['order.channel', 'warehouse', 'events']));
    }

    /**
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    private function late(Builder $query): Builder
    {
        return $query->whereIn('status', self::TABS['delivering'])->where('picked_up_at', '<', now()->subDays(self::LATE_DAYS));
    }

    /** @return Builder<Shipment> */
    private function visible(): Builder
    {
        return Shipment::query()->whereHas('order', fn (Builder $o) => OrderQuery::visible($o));
    }
}
