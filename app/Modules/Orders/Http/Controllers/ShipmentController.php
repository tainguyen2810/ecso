<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CodReconciliationLine;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Http\Resources\ShipmentResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Services\ShipmentExporter;
use App\Modules\Orders\Services\ShipmentImporter;
use App\Modules\Orders\Services\ShipmentQuery;
use App\Modules\Orders\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shipments (vận đơn): the "Vận chuyển & giao hàng" page, the order's shipment card, and what the shop does by hand. */
class ShipmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);
        $filters = $request->validate(ShipmentQuery::rules());

        $page = ShipmentQuery::build($filters)->with(['order.channel', 'warehouse'])
            ->orderByDesc('last_event_at')->orderByDesc('id')->paginate(30);
        ShipmentResource::rememberAreas($page->getCollection());

        return ShipmentResource::collection($page);
    }

    /** "Xuất file": the list as XLSX, with the same filters. */
    public function export(Request $request, ShipmentExporter $exporter, AuditLogger $audit): StreamedResponse
    {
        $this->authorize('viewAny', Order::class);
        $filters = $request->validate(ShipmentQuery::rules());
        $audit->record('shipment.exported', null, ['filters' => array_filter($filters, fn ($v) => $v !== null && $v !== '')]);

        return $exporter->stream($filters);
    }

    /** Step 1 of "Nhập mã vận đơn từ file": read the file and say what each row would do. Writes nothing. */
    public function importPreview(Request $request, ShipmentImporter $importer): JsonResponse
    {
        $this->authorize('importShipments', Order::class);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']]);
        $file = $data['file'];
        $rows = $importer->read($file->getRealPath(), strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv');
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'File không có dòng dữ liệu.']);
        }

        return response()->json(['data' => ['rows' => $rows, 'plan' => $importer->preview($rows)]]);
    }

    /** Step 2: apply the rows the preview showed (checked again, row by row). */
    public function import(Request $request, ShipmentImporter $importer, AuditLogger $audit): JsonResponse
    {
        $this->authorize('importShipments', Order::class);
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.ShipmentImporter::MAX_ROWS],
            'rows.*.order' => ['present', 'nullable', 'string', 'max:64'],
            'rows.*.tracking' => ['present', 'nullable', 'string', 'max:100'],
            'rows.*.carrier' => ['nullable', 'string', 'max:100'],
            'rows.*.weight_g' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $rows = array_values(array_map(fn ($r) => ['order' => (string) ($r['order'] ?? ''), 'tracking' => (string) ($r['tracking'] ?? ''), 'carrier' => $r['carrier'] ?? null, 'weight_g' => isset($r['weight_g']) ? (int) $r['weight_g'] : null], $data['rows']));
        $result = $importer->import($rows);
        $audit->record('shipment.imported', null, ['rows' => count($rows), 'created' => $result['created'], 'updated' => $result['updated'], 'skipped' => $result['skipped'], 'failed' => $result['failed']]);

        return response()->json(['data' => $result]);
    }

    /** KPI: count per tab, late parcels, and the COD carriers hold (delivered, not paid to the shop yet) per carrier. */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $byStatus = ShipmentQuery::visible()->toBase()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return response()->json(['data' => [
            'tabs' => collect(ShipmentQuery::TABS)->map(fn ($statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0))->put('all', (int) $byStatus->sum()),
            'by_status' => $byStatus,
            'late' => ShipmentQuery::late(ShipmentQuery::visible())->count(),
            'late_days' => ShipmentQuery::LATE_DAYS,
            'cod_held' => ShipmentQuery::visible()->toBase()->where('cod_status', Shipment::COD_COLLECTED)
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
            // Statements that paid this parcel's COD or charged its fee (the money is booked there, not here).
            'cod_reconciliations' => CodReconciliationLine::query()->with('reconciliation:id,ulid,number,remitted_at,voided_at')
                ->where('shipment_id', $shipment->id)->orderBy('id')->get()
                ->map(fn (CodReconciliationLine $l) => [
                    'id' => $l->reconciliation?->ulid,
                    'number' => $l->reconciliation?->number,
                    'remitted_at' => $l->reconciliation?->remitted_at?->toIso8601String(),
                    'voided' => $l->reconciliation?->voided_at !== null,
                    'cod' => $l->cod_amount,
                    'fee' => $l->fee,
                    'status' => $l->status,
                ])->values(),
        ]])->response();
    }

    public function store(Request $request, Order $order, ShipmentService $service): JsonResponse
    {
        $this->authorize('view', $order);
        $this->authorize('manageShipments', Order::class);
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
        $this->authorize('manageShipments', Order::class);
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
        $this->authorize('manageShipments', Order::class);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        if ($action === 'cancel' && $shipment->source === Shipment::SOURCE_API && $shipment->tracking_number !== null) {
            // Cancelling only here would leave the parcel live at the carrier.
            throw ValidationException::withMessages(['action' => 'Vận đơn đã tạo trên hãng: dùng "Hủy trên hãng" để hủy cả ở hãng.']);
        }

        return new ShipmentResource($service->act($shipment, $action, $data['reason'] ?? null)->load(['order.channel', 'warehouse', 'events']));
    }
}
