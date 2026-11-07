<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\ShipmentResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Connectors\CarrierRegistry;
use App\Modules\Shipping\Models\CarrierAccount;
use App\Modules\Shipping\Services\CarrierEventApplier;
use App\Modules\Shipping\Services\CarrierShipmentService;
use App\Modules\Shipping\Services\DraftFactory;
use App\Modules\Shipping\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Price, push, cancel, print and refresh parcels at the shop's carriers. */
class CarrierShipmentController extends Controller
{
    public const MAX_BULK = 20;

    public function __construct(
        private readonly DraftFactory $drafts,
        private readonly QuoteService $quotes,
        private readonly CarrierShipmentService $service,
    ) {}

    /** "So sánh giá": for a saved order (order_id) or for the order form (address, lines). */
    public function quote(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $data = $request->validate(['order_id' => ['nullable', 'string', 'size:26'], ...DraftFactory::draftRules()]);
        $draft = filled($data['order_id'] ?? null)
            ? $this->drafts->fromOrder(Order::query()->where('ulid', $data['order_id'])->firstOrFail(), $data)
            : $this->drafts->fromInput($data);

        return response()->json(['data' => ['weight_g' => $draft->weightG, 'cod_amount' => $draft->codAmount, ...$this->quotes->quote($draft)]]);
    }

    public function push(Request $request, Order $order): JsonResponse
    {
        $this->authorize('manageShipments', Order::class);
        $data = $request->validate($this->pushRules());
        $account = CarrierAccount::query()->where('ulid', $data['account_id'])->firstOrFail();
        $shipment = $this->service->push($order, $account, $data['service'], $data['service_name'] ?? null, $this->drafts->fromOrder($order, $data));

        return response()->json(['data' => new ShipmentResource($shipment->load('order'))], 201);
    }

    /** "Đẩy hàng loạt": each order on its own; one refused parcel never stops the others. */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('manageShipments', Order::class);
        $data = $request->validate([
            'orders' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'orders.*.id' => ['required', 'string', 'size:26'],
            'orders.*.account_id' => ['required', 'string', 'size:26'],
            'orders.*.service' => ['required', 'string', 'max:50'],
            'orders.*.service_name' => ['nullable', 'string', 'max:100'],
            'orders.*.weight_g' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            ...collect(DraftFactory::rules())->except('weight_g')->all(),
        ]);
        $common = collect($data)->except('orders')->all();
        $accounts = CarrierAccount::query()->whereIn('ulid', array_column($data['orders'], 'account_id'))->get()->keyBy('ulid');
        $orders = Order::query()->whereIn('ulid', array_column($data['orders'], 'id'))->get()->keyBy('ulid');
        $results = [];
        foreach ($data['orders'] as $row) {
            $order = $orders[$row['id']] ?? null;
            $account = $accounts[$row['account_id']] ?? null;
            try {
                if ($order === null || $account === null) {
                    throw ValidationException::withMessages(['order' => 'Không tìm thấy đơn hoặc kết nối hãng.']);
                }
                $shipment = $this->service->push($order, $account, $row['service'], $row['service_name'] ?? null, $this->drafts->fromOrder($order, [...$common, 'weight_g' => $row['weight_g'] ?? null]));
                $results[] = ['id' => $row['id'], 'ok' => true, 'shipment_id' => $shipment->ulid, 'tracking_number' => $shipment->tracking_number, 'error' => null];
            } catch (ValidationException $e) {
                $results[] = ['id' => $row['id'], 'ok' => false, 'shipment_id' => null, 'tracking_number' => null, 'error' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['data' => ['results' => $results, 'pushed' => count(array_filter($results, fn ($r) => $r['ok'])), 'failed' => count(array_filter($results, fn ($r) => ! $r['ok']))]]);
    }

    public function cancel(Shipment $shipment): JsonResponse
    {
        $this->authorize('manageShipments', Order::class);

        return response()->json(['data' => new ShipmentResource($this->service->cancel($shipment)->load('order'))]);
    }

    public function discard(Shipment $shipment): JsonResponse
    {
        $this->authorize('manageShipments', Order::class);

        return response()->json(['data' => new ShipmentResource($this->service->discard($shipment)->load('order'))]);
    }

    /** "Cập nhật từ hãng": asks the carrier for the parcel's history now. */
    public function refresh(Shipment $shipment, CarrierRegistry $carriers, CarrierEventApplier $applier): JsonResponse
    {
        $this->authorize('manageShipments', Order::class);
        $account = $shipment->carrier_account_id ? CarrierAccount::query()->find($shipment->carrier_account_id) : null;
        if ($account === null || $shipment->tracking_number === null || ! $carriers->has($account->carrier) || $account->credentials === null) {
            throw ValidationException::withMessages(['shipment' => 'Vận đơn này không cập nhật được từ hãng.']);
        }
        try {
            $new = $applier->apply($account, $carriers->get($account->carrier)->track($account, $shipment));
        } catch (CarrierException $e) {
            throw ValidationException::withMessages(['shipment' => $e->getMessage()]);
        }

        return response()->json(['data' => ['new_events' => $new, 'shipment' => new ShipmentResource($shipment->refresh()->load('order'))]]);
    }

    public function labels(Request $request): JsonResponse
    {
        $this->authorize('print', Order::class);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['string', 'size:26'], 'size' => ['nullable', Rule::in(['a5', '80x80', '52x70'])]]);
        $shipments = Shipment::query()->whereIn('ulid', $data['ids'])->get()->all();
        if (count($shipments) !== count(array_unique($data['ids']))) {
            throw ValidationException::withMessages(['ids' => 'Có vận đơn không tồn tại.']);
        }

        return response()->json(['data' => ['url' => $this->service->labelUrl($shipments, $data['size'] ?? 'a5')]]);
    }

    private function pushRules(): array
    {
        return ['account_id' => ['required', 'string', 'size:26'], 'service' => ['required', 'string', 'max:50'], 'service_name' => ['nullable', 'string', 'max:100'], ...DraftFactory::rules()];
    }
}
