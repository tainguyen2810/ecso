<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PackScanError;
use App\Modules\Orders\Services\OrderQuery;
use App\Modules\Orders\Services\PackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Packing screen (Đóng gói bằng quét mã) and the pick list. */
class PackingController extends Controller
{
    public function lookup(Request $request, PackingService $packing): JsonResponse
    {
        $this->authorize('pack', Order::class);
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $packing->present($packing->find($data['code']))]);
    }

    public function stats(PackingService $packing): JsonResponse
    {
        $this->authorize('pack', Order::class);

        return response()->json(['data' => $packing->stats()]);
    }

    public function error(Request $request, Order $order, PackingService $packing): JsonResponse
    {
        $this->authorize('pack', Order::class);
        $this->authorize('view', $order);
        $data = $request->validate(['code' => ['required', 'string', 'max:100'], 'kind' => ['required', Rule::in(PackScanError::KINDS)]]);
        $packing->logError($order, $data['code'], $data['kind']);

        return response()->json(['data' => ['ok' => true]], 201);
    }

    public function finish(Request $request, Order $order, PackingService $packing): JsonResponse
    {
        $this->authorize('pack', Order::class);
        $this->authorize('view', $order);
        $data = $request->validate([
            'scanned' => ['present', 'array'],
            'scanned.*' => ['integer', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $order = $packing->finish($order, $data['scanned'], $data['reason'] ?? null);

        return response()->json(['data' => ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'status' => $order->status]]);
    }

    public function pickList(Request $request, PackingService $packing): JsonResponse
    {
        $this->authorize('print', Order::class);
        // Either the orders ticked in the list, or (`waiting`) every order waiting to be packed, oldest first.
        $data = $request->validate([
            'ids' => ['required_without:waiting', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string'],
            'waiting' => ['nullable', 'boolean'],
        ]);
        $orders = OrderQuery::visible(Order::query())->with(['items.variant.product'])
            ->when(isset($data['ids']), fn ($q) => $q->whereIn('ulid', $data['ids']), fn ($q) => $q->where('status', OrderStatus::READY_TO_PACK))
            ->orderBy('placed_at')->limit(200)->get();

        return response()->json(['data' => [
            'orders' => $orders->map(fn (Order $o) => $o->external_order_id)->values(),
            'rows' => $packing->pickList($orders),
        ]]);
    }
}
