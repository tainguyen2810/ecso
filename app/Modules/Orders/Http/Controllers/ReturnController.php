<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ReturnController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OrderReturn::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(OrderReturn::FLOW))],
            'type' => ['nullable', Rule::in(OrderReturn::TYPES)],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        return ReturnResource::collection(OrderReturn::query()->with(['order', 'warehouse'])->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")
                ->orWhereHas('order', fn ($o) => $o->where('external_order_id', 'like', "%{$term}%"))))
            ->orderByRaw("FIELD(status, 'received', 'in_transit', 'requested', 'approved', 'inspected', 'closed', 'rejected')")
            ->orderByDesc('id')->paginate(30));
    }

    public function show(OrderReturn $return): ReturnResource
    {
        $this->authorize('view', $return);

        return new ReturnResource($return->load(['order', 'warehouse', 'lines.item', 'lines.variant', 'creator', 'inspector']));
    }

    public function store(Request $request, Order $order, ReturnService $returns): JsonResponse
    {
        $this->authorize('create', OrderReturn::class);
        $data = $request->validate([
            'type' => ['required', Rule::in([OrderReturn::TYPE_RETURN, OrderReturn::TYPE_REFUND_ONLY])],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines' => ['array', 'max:100'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $return = $returns->create($order, $data);

        return (new ReturnResource($return->load(['order', 'lines.item', 'lines.variant'])))->response()->setStatusCode(201);
    }

    public function move(Request $request, OrderReturn $return, ReturnService $returns): ReturnResource
    {
        $this->authorize('inspect', $return);
        $data = $request->validate([
            'status' => ['required', Rule::in([OrderReturn::APPROVED, OrderReturn::REJECTED, OrderReturn::IN_TRANSIT, OrderReturn::RECEIVED, OrderReturn::CLOSED])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return new ReturnResource($returns->move($return, $data['status'], $data['note'] ?? null)->load(['order', 'warehouse', 'lines.item', 'lines.variant']));
    }

    public function inspect(Request $request, OrderReturn $return, ReturnService $returns): ReturnResource
    {
        $this->authorize('inspect', $return);
        $data = $request->validate([
            'warehouse_id' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.received_qty' => ['required', 'integer', 'min:0'],
            'lines.*.sellable_qty' => ['required', 'integer', 'min:0'],
            'lines.*.damaged_qty' => ['required', 'integer', 'min:0'],
        ]);
        $warehouse = Warehouse::query()->where('ulid', $data['warehouse_id'])->where('status', 'active')->firstOrFail();

        return new ReturnResource($returns->inspect($return, $warehouse, $data['lines'], $data['note'] ?? null)->load(['order', 'warehouse', 'lines.item', 'lines.variant', 'inspector']));
    }
}
