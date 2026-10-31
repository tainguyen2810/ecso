<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderQuery;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request, OrderQuery $orders): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $filters = $this->filters($request);

        $page = $orders->build($filters, $request->user()->id)
            ->with(['account', 'assignee', 'items.variant'])->withCount('items')
            ->orderBy(match ($filters['sort'] ?? 'newest') {
                'ship_by' => 'ship_by_at',
                'oldest', 'newest' => 'placed_at',
                default => 'placed_at',
            }, ($filters['sort'] ?? 'newest') === 'newest' ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 30));

        return OrderResource::collection($page)->additional(['meta' => ['counts' => $orders->tabCounts($filters, $request->user()->id)]])->response();
    }

    public function show(Order $order, ReservationLedger $ledger, ConnectorRegistry $connectors): JsonResponse
    {
        $this->authorize('view', $order);
        $order->load(['account', 'warehouse', 'assignee', 'items.variant.product', 'items.listingVariant']);
        $reservations = $ledger->snapshot(Order::STOCK_REFERENCE, $order->items->pluck('id')->all());
        $capabilities = $connectors->has($order->marketplace) ? $connectors->for($order->marketplace)->capabilities() : Capability::allUnknown();

        return (new OrderResource($order))->additional(['data' => [
            'items' => $order->items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'variant_name' => $i->variant_name,
                'seller_sku' => $i->seller_sku,
                'external_sku_id' => $i->external_sku_id,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'discount' => $i->discount,
                'mapping_status' => $i->mapping_status,
                'listing_id' => $i->listingVariant?->ulid,
                'variant' => $i->variant ? ['id' => $i->variant->ulid, 'sku' => $i->variant->sku, 'name' => $i->variant->displayName()] : null,
                'reservation' => isset($reservations[$i->id]) ? [
                    'quantity' => $reservations[$i->id]['quantity'],
                    'shortage' => $reservations[$i->id]['shortage'],
                    'status' => $reservations[$i->id]['status'],
                ] : null,
            ]),
            'history' => OrderStatusHistory::query()->with('actor:id,name')->where('order_id', $order->id)->orderBy('id')->get()->map(fn (OrderStatusHistory $h) => [
                'from' => $h->from_status,
                'to' => $h->to_status,
                'raw_status' => $h->raw_status,
                'source' => $h->source,
                'actor' => $h->actor?->name,
                'note' => $h->note,
                'occurred_at' => $h->occurred_at?->toIso8601String(),
                'created_at' => $h->created_at->toIso8601String(),
            ]),
            'notes' => $order->notes()->with('user:id,name')->get()->map(fn (OrderNote $n) => [
                'id' => $n->id, 'body' => $n->body, 'user' => $n->user?->name, 'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'returns' => ReturnResource::collection($order->returns()->get()),
            'actions' => collect(OrderActions::ALLOWED_FROM)->map(fn ($from, $action) => [
                'allowed' => in_array($order->status, $from, true),
                'capability' => $capabilities[Capability::ORDER_ACTIONS[$action]] ?? Capability::UNKNOWN,
            ]),
            'capabilities' => $capabilities,
        ]])->response();
    }

    public function action(Request $request, Order $order, string $action, OrderActions $actions): OrderResource
    {
        $this->authorize('update', $order);
        $params = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new OrderResource($actions->perform($order, $action, $params)->load(['account', 'assignee']));
    }

    public function bulk(Request $request, OrderActions $actions): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string'],
            'action' => ['required', Rule::in(['confirm', 'pack', 'handover', 'assign'])],
            'user_id' => ['nullable', 'string'],
        ]);
        $this->authorize($data['action'] === 'assign' ? 'assign' : 'update', Order::class);
        $orders = Order::query()->with('account')->whereIn('ulid', $data['ids'])->get();

        if ($data['action'] === 'assign') {
            $user = $this->member($data['user_id'] ?? null);
            foreach ($orders as $order) {
                $actions->assign($order, $user);
            }

            return response()->json(['data' => $orders->map(fn ($o) => ['id' => $o->ulid, 'ok' => true, 'message' => null])]);
        }

        return response()->json(['data' => $actions->bulk($orders, $data['action'])]);
    }

    public function assign(Request $request, Order $order, OrderActions $actions): OrderResource
    {
        $this->authorize('assign', $order);
        $data = $request->validate(['user_id' => ['nullable', 'string']]);

        return new OrderResource($actions->assign($order, $this->member($data['user_id'] ?? null))->load(['account', 'assignee']));
    }

    public function note(Request $request, Order $order, OrderActions $actions): JsonResponse
    {
        $this->authorize('update', $order);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $note = $actions->addNote($order, $data['body']);

        return response()->json(['data' => ['id' => $note->id, 'body' => $note->body, 'user' => $request->user()->name, 'created_at' => $note->created_at?->toIso8601String()]], 201);
    }

    /** Re-reads the order from the marketplace API. */
    public function sync(Request $request, Order $order, OrderSync $sync): JsonResponse
    {
        $this->authorize('update', $order);
        try {
            $result = $sync->syncOne($order->account, $order->external_order_id, 'user', null, $request->user()->id);
        } catch (ConnectorException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return response()->json(['data' => ['result' => $result['result']]]);
    }

    /** Data for packing slips (rendered to PDF in the browser) and marks the orders as printed. */
    public function packingSlips(Request $request): JsonResponse
    {
        $this->authorize('print', Order::class);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['string']]);
        $orders = Order::query()->with(['account', 'warehouse', 'items.variant'])->whereIn('ulid', $data['ids'])->orderBy('placed_at')->get();

        Order::query()->whereIn('id', $orders->pluck('id'))->whereNull('packing_slip_printed_at')->update(['packing_slip_printed_at' => now()]);

        return response()->json(['data' => $orders->map(fn (Order $o) => [
            'id' => $o->ulid,
            'external_order_id' => $o->external_order_id,
            'shop' => $o->account?->shop_name,
            'marketplace' => $o->marketplace,
            'placed_at' => $o->placed_at?->toIso8601String(),
            'ship_by_at' => $o->ship_by_at?->toIso8601String(),
            'buyer_name' => $o->buyer_name,
            'buyer_phone' => $o->buyer_phone_masked,
            'shipping_address' => $o->shipping_address,
            'carrier' => $o->shipping_carrier,
            'tracking_number' => $o->tracking_number,
            'is_cod' => $o->is_cod,
            'total' => $o->total,
            'note' => $o->buyer_note,
            'warehouse' => $o->warehouse?->name,
            'items' => $o->items->map(fn (OrderItem $i) => [
                'sku' => $i->variant?->sku ?? $i->seller_sku,
                'name' => $i->name,
                'variant_name' => $i->variant_name,
                'quantity' => $i->quantity,
            ]),
        ])]);
    }

    /**
     * Dashboard figures. "Ordered revenue" is the buyer-paid total of today's non-cancelled orders, not cash received
     * (marketplace fees and settlement come with the finance module).
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $today = now()->startOfDay();
        $todayOrders = Order::query()->where('placed_at', '>=', $today)->where('status', '!=', OrderStatus::CANCELLED);

        return response()->json(['data' => [
            'today_orders' => (clone $todayOrders)->count(),
            'today_ordered_revenue' => (string) (clone $todayOrders)->sum('total'),
            'to_process' => Order::query()->whereIn('status', OrderStatus::OPEN)->count(),
            'overdue' => Order::query()->whereIn('status', OrderStatus::OPEN)->where('ship_by_at', '<', now())->count(),
            'shortage' => Order::query()->where('has_shortage', true)->whereIn('status', OrderStatus::OPEN)->count(),
            'recent' => OrderResource::collection(Order::query()->with(['account'])->orderByDesc('placed_at')->orderByDesc('id')->limit(6)->get()),
        ]]);
    }

    /** Members who can be assigned orders. */
    public function assignees(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $members = TenantUser::query()->with('user:id,ulid,name')->where('status', TenantUser::STATUS_ACTIVE)->get();

        return response()->json(['data' => $members->filter(fn ($m) => $m->user)->map(fn ($m) => ['id' => $m->user->ulid, 'name' => $m->user->name])->values()]);
    }

    private function member(?string $ulid): ?User
    {
        if ($ulid === null) {
            return null;
        }

        $user = User::query()->where('ulid', $ulid)->first();
        $isMember = $user && TenantUser::query()->where('user_id', $user->id)->where('status', TenantUser::STATUS_ACTIVE)->exists();
        if (! $isMember) {
            throw ValidationException::withMessages(['user_id' => 'Người được phân công không thuộc cửa hàng này.']);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(OrderStatus::TABS))],
            'status' => ['nullable', Rule::in(OrderStatus::ALL)],
            'account' => ['nullable', 'string'],
            'marketplace' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string'],
            'assignee' => ['nullable', 'string'],
            'flags' => ['nullable', 'array'],
            'flags.*' => [Rule::in(['shortage', 'unmapped', 'overdue', 'due_soon', 'warning', 'not_printed'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'ship_by'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
    }
}
