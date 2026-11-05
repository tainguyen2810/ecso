<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Http\Resources\ShipmentResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Services\OrderQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Shipments (vận đơn), read only for now: they follow their orders (shipping round S2). */
class ShipmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ShipmentStatus::ALL)],
            'carrier' => ['nullable', 'string', 'max:20'],
            'cod_status' => ['nullable', Rule::in([Shipment::COD_NONE, Shipment::COD_PENDING, Shipment::COD_COLLECTED, Shipment::COD_REMITTED, Shipment::COD_RETURNED])],
            'order_id' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return ShipmentResource::collection($this->visible()->with(['order', 'warehouse'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['carrier'] ?? null, fn ($q, $c) => $q->where('carrier', $c))
            ->when($filters['cod_status'] ?? null, fn ($q, $c) => $q->where('cod_status', $c))
            ->when($filters['order_id'] ?? null, fn ($q, $id) => $q->whereHas('order', fn ($o) => $o->where('ulid', $id)))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('tracking_number', 'like', "%{$term}%")
                ->orWhereHas('order', fn ($o) => $o->where('external_order_id', 'like', "%{$term}%"))))
            ->orderByDesc('id')->paginate(30));
    }

    /** Count per status, and the COD carriers hold (delivered, not paid to the shop yet), per carrier. */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        return response()->json(['data' => [
            'by_status' => $this->visible()->toBase()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'cod_held' => $this->visible()->toBase()->where('cod_status', Shipment::COD_COLLECTED)
                ->selectRaw('carrier, SUM(cod_amount) as amount, COUNT(*) as n')->groupBy('carrier')->get()
                ->map(fn ($r) => ['carrier' => $r->carrier, 'amount' => number_format((float) $r->amount, 2, '.', ''), 'count' => (int) $r->n])->values(),
        ]]);
    }

    public function show(Shipment $shipment): ShipmentResource
    {
        $this->authorize('view', $shipment->order);

        return new ShipmentResource($shipment->load(['order', 'warehouse', 'events']));
    }

    /** @return Builder<Shipment> */
    private function visible(): Builder
    {
        return Shipment::query()->whereHas('order', fn (Builder $o) => OrderQuery::visible($o));
    }
}
