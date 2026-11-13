<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Http\Resources\ShipmentResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\Models\OrderRefund;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderExporter;
use App\Modules\Orders\Services\OrderQuery;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Orders\Services\ReturnService;
use App\Modules\Orders\Services\ShipmentSync;
use App\Modules\Orders\Support\OrderTags;
use App\Modules\Orders\Support\Tracking;
use App\Modules\Sales\Http\Resources\PaymentResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Services\ManualOrderService;
use App\Modules\Sales\Services\PaymentService;
use App\Modules\Tenancy\Models\TenantUser;
use App\Shared\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request, OrderQuery $orders): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $filters = $this->filters($request);

        $page = $orders->build($filters, $request->user()->id)
            ->with(['account', 'channel', 'customer', 'assignee', 'items.variant'])->withCount('items')
            ->orderBy(match ($filters['sort'] ?? 'newest') {
                'ship_by' => 'ship_by_at',
                'oldest', 'newest' => 'placed_at',
                default => 'placed_at',
            }, ($filters['sort'] ?? 'newest') === 'newest' ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 30));

        return OrderResource::collection($page)->additional(['meta' => ['counts' => $orders->tabCounts($filters, $request->user()->id)]])->response();
    }

    /** "Orders to handle" strip above the order list. */
    public function pipeline(OrderQuery $orders): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        return response()->json(['data' => $orders->pipeline()]);
    }

    /** XLSX of the orders matching the list filters (all tabs and pages, up to OrderExporter::MAX_ROWS). */
    public function export(Request $request, OrderExporter $exporter): StreamedResponse
    {
        $this->authorize('viewAny', Order::class);

        return $exporter->stream($this->filters($request), $request->user()->id);
    }

    public function show(Order $order, ReservationLedger $ledger, ConnectorRegistry $connectors, ReturnService $returnService): JsonResponse
    {
        $this->authorize('view', $order);
        $returnable = $returnService->returnableQuantities($order);
        $order->load(['account', 'channel', 'customer', 'creator', 'warehouse', 'assignee', 'items.variant.product', 'items.listingVariant', 'payments.creator']);
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
                'refunded_quantity' => (int) $i->refunded_quantity,
                // Shipped and not yet covered by a return: the most a new return may take back.
                'returnable' => $returnable[$i->id] ?? 0,
                'unit_price' => $i->unit_price,
                'discount' => $i->discount,
                'is_gift' => $i->promotion_id !== null,
                'tax' => $i->tax,
                'mapping_status' => $i->mapping_status,
                'listing_id' => $i->listingVariant?->ulid,
                'variant' => $i->variant ? ['id' => $i->variant->ulid, 'sku' => $i->variant->sku, 'name' => $i->variant->displayName()] : null,
                'reservation' => isset($reservations[$i->id]) ? [
                    'quantity' => $reservations[$i->id]['quantity'],
                    'shortage' => $reservations[$i->id]['shortage'],
                    'status' => $reservations[$i->id]['status'],
                ] : null,
            ]),
            'promotions' => DB::table('promotion_usages')->join('promotions', 'promotions.id', '=', 'promotion_usages.promotion_id')->where('promotion_usages.order_id', $order->id)
                ->get(['promotions.ulid', 'promotions.name', 'promotions.type', 'promotions.code', 'promotion_usages.amount'])
                ->map(fn (object $p) => ['id' => $p->ulid, 'name' => $p->name, 'type' => $p->type, 'code' => $p->code, 'amount' => number_format((float) $p->amount, 2, '.', '')]),
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
            // What each edit changed, newest first.
            'edits' => AuditLog::query()->with('actor:id,name')->where('tenant_id', $order->tenant_id)->where('action', 'order.updated')->where('subject_type', 'Order')->where('subject_id', $order->id)
                ->latest('id')->limit(50)->get()->map(fn (AuditLog $log) => [
                    'at' => $log->created_at->toIso8601String(),
                    'actor' => $log->actor?->name,
                    'changes' => self::editChanges($log->getAttribute('changes')),
                ]),
            'notes' => $order->notes()->with('user:id,name')->get()->map(fn (OrderNote $n) => [
                'id' => $n->id, 'body' => $n->body, 'user' => $n->user?->name, 'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'returns' => ReturnResource::collection($order->returns()->with('exchangeOrder')->get()),
            'shipments' => ShipmentResource::collection(Shipment::query()->where('order_id', $order->id)->with(['events', 'order'])->orderBy('id')->get()),
            'refunds' => OrderRefund::query()->with('orderReturn:id,ulid,number,status')->where('order_id', $order->id)->orderBy('refunded_at')->get()
                ->map(fn (OrderRefund $r) => [
                    'id' => $r->ulid, 'external_refund_id' => $r->external_refund_id, 'amount' => $r->amount, 'reason' => $r->reason,
                    'refunded_at' => $r->refunded_at?->toIso8601String(),
                    'lines' => collect($r->lines ?? [])->map(fn ($l) => [
                        'name' => $order->items->firstWhere('id', $l['order_item_id'])?->name, 'quantity' => $l['quantity'], 'amount' => $l['amount'],
                    ])->values(),
                    'return' => $r->orderReturn ? ['id' => $r->orderReturn->ulid, 'number' => $r->orderReturn->number, 'status' => $r->orderReturn->status] : null,
                ]),
            // This order was taken in exchange for returned goods.
            'exchanged_from' => OrderReturn::query()->with('order')->where('exchange_order_id', $order->id)->get()->map(fn (OrderReturn $r) => [
                'id' => $r->ulid, 'number' => $r->number, 'credit' => $r->exchange_credit,
                'order' => ['id' => $r->order->ulid, 'external_order_id' => $r->order->external_order_id],
            ]),
            'actions' => $order->isManual()
                ? collect(ManualOrderService::ACTIONS)->map(fn ($def) => ['allowed' => in_array($order->status, $def[0], true), 'capability' => Capability::SUPPORTED])
                : collect(OrderActions::ALLOWED_FROM)->map(fn ($from, $action) => [
                    'allowed' => in_array($order->status, $from, true),
                    'capability' => $capabilities[Capability::ORDER_ACTIONS[$action]] ?? Capability::UNKNOWN,
                ]),
            'editable' => $order->isManual() && in_array($order->status, ManualOrderService::EDITABLE, true),
            // none | partial | full: how much of what was shipped has come back on returns.
            'return_state' => $returnService->returnState($order),
            'payments' => PaymentResource::collection($order->payments),
            // Website order: what the store reports paid but nobody has confirmed in a fund yet.
            'unconfirmed_amount' => $order->marketplace === PaymentService::WEBSITE ? app(PaymentService::class)->unconfirmed($order) : null,
            'created_by' => $order->creator?->name,
            'customer_stats' => $order->customer_id ? $this->customerStats($order) : null,
            'capabilities' => $capabilities,
        ]])->response();
    }

    public function action(Request $request, Order $order, string $action, OrderActions $actions): OrderResource
    {
        $this->authorize('update', $order);
        if ($action === 'cancel') {
            $this->authorize('cancel', $order);
        }
        $params = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            // Kept for orders the shop ships itself (own channels, website); a marketplace books its own carrier.
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ]);

        return new OrderResource($actions->perform($order, $action, $params)->load(['account', 'channel', 'assignee']));
    }

    public function bulk(Request $request, OrderActions $actions, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string'],
            'action' => ['required', Rule::in(['confirm', 'pack', 'handover', 'cancel', 'pay', 'assign'])],
            'user_id' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
            'method' => [Rule::requiredIf($request->input('action') === 'pay'), Rule::in(OrderPayment::METHODS)],
        ]);
        $this->authorize(match ($data['action']) {
            'assign' => 'assign',
            'pay' => 'pay',
            default => 'update',
        }, Order::class);
        if ($data['action'] === 'cancel') {
            $this->authorize('cancel', Order::class);
        }
        $orders = Order::query()->with(['account', 'channel'])->whereIn('ulid', $data['ids'])->get();

        if ($data['action'] === 'assign') {
            $user = $this->member($data['user_id'] ?? null);
            foreach ($orders as $order) {
                $actions->assign($order, $user);
            }

            return response()->json(['data' => $orders->map(fn ($o) => ['id' => $o->ulid, 'ok' => true, 'message' => null])]);
        }

        if ($data['action'] === 'pay') {
            return response()->json(['data' => $this->payInFull($orders, $data['method'], $payments)]);
        }

        return response()->json(['data' => $actions->bulk($orders, $data['action'], ['reason' => $data['reason'] ?? null])]);
    }

    public function assign(Request $request, Order $order, OrderActions $actions): OrderResource
    {
        $this->authorize('assign', $order);
        $data = $request->validate(['user_id' => ['nullable', 'string']]);

        return new OrderResource($actions->assign($order, $this->member($data['user_id'] ?? null))->load(['account', 'assignee']));
    }

    /** Internal tags and the date promised to the buyer: editable on any order, marketplace ones included. */
    public function meta(Request $request, Order $order, AuditLogger $audit, ShipmentSync $shipments): OrderResource
    {
        $this->authorize('update', $order);
        $data = $request->validate([
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.OrderTags::MAX],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'deliver_on' => ['sometimes', 'nullable', 'date'],
            // Carrier and tracking number can be corrected after handover, on orders the shop ships itself.
            'carrier' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $shipping = [];
        if (array_key_exists('carrier', $data) || array_key_exists('tracking_number', $data)) {
            if (! $order->isManual() && $order->marketplace !== PaymentService::WEBSITE) {
                throw ValidationException::withMessages(['tracking_number' => 'Mã vận đơn của đơn sàn do sàn cấp, không sửa ở đây.']);
            }
            $before = ['carrier' => $order->shipping_carrier, 'tracking_number' => $order->tracking_number];
            if (array_key_exists('carrier', $data)) {
                $order->shipping_carrier = filled($data['carrier']) ? trim($data['carrier']) : null;
            }
            if (array_key_exists('tracking_number', $data)) {
                $order->tracking_number = Tracking::normalize($data['tracking_number']);
                Tracking::assertUnique($order, $order->tracking_number);
            }
            $shipping = ['before' => $before, 'after' => ['carrier' => $order->shipping_carrier, 'tracking_number' => $order->tracking_number]];
        }
        if (array_key_exists('tags', $data)) {
            $order->tags = OrderTags::normalize($data['tags']);
        }
        if (array_key_exists('deliver_on', $data)) {
            $order->deliver_on = $data['deliver_on'];
        }
        DB::transaction(function () use ($order, $shipping, $shipments) {
            $order->save();
            if ($shipping) {
                $shipments->apply($order);
            }
        });
        $audit->record('order.meta_updated', $order, ['order' => $order->external_order_id, 'tags' => $order->tags, 'deliver_on' => $order->deliver_on?->toDateString()] + ($shipping ? ['shipping' => $shipping] : []));

        return new OrderResource($order->load(['account', 'channel', 'customer', 'assignee']));
    }

    /** Tags already used on orders, for suggestions. */
    public function tags(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $tags = Order::query()->whereNotNull('tags')->latest('id')->limit(2000)->pluck('tags')->flatten()
            ->unique(fn ($t) => mb_strtolower((string) $t))->sort()->values();

        return response()->json(['data' => $tags]);
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
        if ($order->isManual()) {
            throw ValidationException::withMessages(['order' => 'Đơn bán tại kênh riêng không có trên sàn để đồng bộ.']);
        }
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
        $orders = Order::query()->with(['account', 'channel', 'warehouse', 'items.variant'])->whereIn('ulid', $data['ids'])->orderBy('placed_at')->get();

        Order::query()->whereIn('id', $orders->pluck('id'))->whereNull('packing_slip_printed_at')->update(['packing_slip_printed_at' => now()]);

        return response()->json(['data' => $orders->map(fn (Order $o) => [
            'id' => $o->ulid,
            'external_order_id' => $o->external_order_id,
            'shop' => $o->account->shop_name ?? $o->channel?->name,
            'marketplace' => $o->marketplace,
            'placed_at' => $o->placed_at?->toIso8601String(),
            'ship_by_at' => $o->ship_by_at?->toIso8601String(),
            'buyer_name' => $o->buyer_name,
            'buyer_phone' => $o->isManual() ? $o->buyer_phone : $o->buyer_phone_masked,
            'shipping_address' => $o->shipping_address,
            'carrier' => $o->shipping_carrier,
            'tracking_number' => $o->tracking_number,
            'is_cod' => $o->is_cod,
            'total' => $o->total,
            'note' => $o->buyer_note,
            'warehouse' => $o->warehouse?->name,
            'items' => $o->items->map(fn (OrderItem $i) => [
                'sku' => $i->variant->sku ?? $i->seller_sku,
                'name' => $i->name,
                'variant_name' => $i->variant_name,
                'quantity' => $i->quantity,
            ]),
        ])]);
    }

    /**
     * Dashboard figures. "Ordered revenue" is the buyer-paid total of today's non-cancelled orders, not cash received
     * (marketplace fees and settlement come with the finance module). `revenue_by_day` covers the last `days` days
     * (7 or 30) split by channel: one key per marketplace, and one per own sales channel ("channel:<ulid>", or
     * `manual` for an own-channel order without a channel), same rule as today's figure.
     */
    public function summary(Request $request, OrderQuery $orders): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $days = (int) ($request->validate(['days' => ['nullable', Rule::in(['7', '30'])]])['days'] ?? 7);
        $today = now(ReportRange::timezone())->startOfDay();
        $placed = fn ($from, $to = null) => Order::query()->where('placed_at', '>=', $from)->when($to, fn ($q) => $q->where('placed_at', '<', $to));
        $todayOrders = $placed($today)->where('status', '!=', OrderStatus::CANCELLED);

        $from = $today->copy()->subDays($days - 1);
        $rows = $placed($from)->where('status', '!=', OrderStatus::CANCELLED)->toBase()
            ->selectRaw(ReportRange::day('placed_at', $today).' as day, marketplace, sales_channel_id, SUM(total) as revenue, COUNT(*) as orders')
            ->groupByRaw(ReportRange::day('placed_at', $today).', marketplace, sales_channel_id')->get();
        // Own-channel orders are split per sales channel (shop counter, Facebook, Zalo...): key "channel:<ulid>".
        $salesChannels = SalesChannel::query()->whereIn('id', $rows->where('marketplace', 'manual')->pluck('sales_channel_id')->filter()->unique())->get()->keyBy('id');
        $keyOf = function ($r) use ($salesChannels): string {
            $channel = $r->marketplace === 'manual' && $r->sales_channel_id ? $salesChannels->get($r->sales_channel_id) : null;

            return $channel ? 'channel:'.$channel->ulid : (string) $r->marketplace;
        };
        $byDay = [];
        for ($d = $from->copy(); $d->lte($today); $d->addDay()) {
            $byDay[$d->toDateString()] = ['date' => $d->toDateString(), 'revenue' => '0.00', 'orders' => 0, 'channels' => []];
        }
        $byChannel = [];
        $channelInfo = [];
        foreach ($rows as $r) {
            $day = substr((string) $r->day, 0, 10);
            if (! isset($byDay[$day])) {
                continue;
            }
            $amount = number_format((float) $r->revenue, 2, '.', '');
            $byDay[$day]['revenue'] = bcadd($byDay[$day]['revenue'], $amount, 2);
            $byDay[$day]['orders'] += (int) $r->orders;
            $key = $keyOf($r);
            $byDay[$day]['channels'][$key] = bcadd($byDay[$day]['channels'][$key] ?? '0', $amount, 2);
            $byChannel[$key] = bcadd($byChannel[$key] ?? '0', $amount, 2);
            $channelInfo[$key] ??= str_starts_with($key, 'channel:')
                ? ['marketplace' => 'manual', 'channel' => ['name' => $salesChannels->get($r->sales_channel_id)->name, 'type' => $salesChannels->get($r->sales_channel_id)->type]]
                : ['marketplace' => $key, 'channel' => null];
        }
        arsort($byChannel, SORT_NUMERIC);

        return response()->json(['data' => [
            'today_orders' => (clone $todayOrders)->count(),
            'today_ordered_revenue' => (string) (clone $todayOrders)->sum('total'),
            'yesterday_ordered_revenue' => (string) $placed($today->copy()->subDay(), $today)->where('status', '!=', OrderStatus::CANCELLED)->sum('total'),
            'today_cancelled' => $placed($today)->where('status', OrderStatus::CANCELLED)->count(),
            'today_returns' => $placed($today)->whereIn('status', OrderStatus::TABS['returns'])->count(),
            'queues' => $orders->tabCounts([], $request->user()->id),
            'days' => $days,
            'revenue_by_day' => array_values(array_map(fn ($d) => [...$d, 'channels' => (object) $d['channels']], $byDay)),
            'revenue_by_channel' => collect($byChannel)->map(fn ($v, $k) => ['key' => (string) $k, ...$channelInfo[$k], 'revenue' => $v])->values(),
            'to_process' => Order::query()->whereIn('status', OrderStatus::OPEN)->count(),
            'overdue' => Order::query()->whereIn('status', OrderStatus::OPEN)->where('ship_by_at', '<', now())->count(),
            'shortage' => Order::query()->where('has_shortage', true)->whereIn('status', OrderStatus::OPEN)->count(),
            'recent' => OrderResource::collection(Order::query()->with(['account', 'channel'])->orderByDesc('placed_at')->orderByDesc('id')->limit(6)->get()),
        ]]);
    }

    /** Members who can be assigned orders. */
    public function assignees(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);
        $members = TenantUser::query()->with('user:id,ulid,name')->where('status', TenantUser::STATUS_ACTIVE)->get();

        return response()->json(['data' => $members->filter(fn (TenantUser $m) => $m->user !== null)->map(fn ($m) => ['id' => $m->user->ulid, 'name' => $m->user->name])->values()]);
    }

    /**
     * "Thanh toán nhanh": collects what is still due on each manual order in one payment. One failure does not stop
     * the others.
     *
     * @param  iterable<Order>  $orders
     * @return array<int, array{id: string, ok: bool, message: string|null}>
     */
    private function payInFull(iterable $orders, string $method, PaymentService $payments): array
    {
        $results = [];
        foreach ($orders as $order) {
            $due = $order->balanceDue();
            if (! $order->isManual() || $order->status === OrderStatus::CANCELLED || bccomp($due, '0', 2) <= 0) {
                $results[] = ['id' => $order->ulid, 'ok' => false, 'message' => match (true) {
                    ! $order->isManual() => 'Đơn sàn do sàn thu tiền.',
                    $order->status === OrderStatus::CANCELLED => 'Đơn đã hủy.',
                    default => 'Đơn đã thu đủ tiền.',
                }];

                continue;
            }
            try {
                $payments->record($order, ['method' => $method, 'amount' => $due, 'note' => 'Thanh toán nhanh']);
                $results[] = ['id' => $order->ulid, 'ok' => true, 'message' => null];
            } catch (ValidationException $e) {
                $results[] = ['id' => $order->ulid, 'ok' => false, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return $results;
    }

    /**
     * Buyer summary shown beside the order: what they bought, still owe, returned and refused.
     *
     * @return array<string, mixed>
     */
    private function customerStats(Order $order): array
    {
        $customer = Customer::query()->withStats()->whereKey($order->customer_id)->first();
        $orderIds = Order::query()->where('customer_id', $order->customer_id)->select('id');

        return [
            'orders_count' => (int) ($customer->orders_count ?? 0),
            'total_spent' => bcadd((string) ($customer->total_spent ?? 0), '0', 2),
            'debt' => bcadd((string) ($customer->debt ?? 0), '0', 2),
            'returns_count' => OrderReturn::query()->whereIn('order_id', $orderIds)->where('type', OrderReturn::TYPE_RETURN)->count(),
            'failed_deliveries' => Order::query()->where('customer_id', $order->customer_id)->where('status', OrderStatus::FAILED_DELIVERY)->count(),
        ];
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
            'channel' => ['nullable', 'string'],
            'customer' => ['nullable', 'string'],
            'warehouse' => ['nullable', 'string'],
            'source' => ['nullable', Rule::in(['manual', 'marketplace'])],
            'payment_status' => ['nullable', Rule::in([Order::PAYMENT_UNPAID, Order::PAYMENT_PARTIAL, Order::PAYMENT_PAID])],
            'marketplace' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string'],
            'tag' => ['nullable', 'string', 'max:40'],
            'deliver_from' => ['nullable', 'date'],
            'deliver_to' => ['nullable', 'date'],
            'assignee' => ['nullable', 'string'],
            'flags' => ['nullable', 'array'],
            'flags.*' => [Rule::in(['shortage', 'unmapped', 'overdue', 'due_soon', 'warning', 'not_printed', 'payment_due'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'ship_by'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
    }

    /** @return array<int, string> */
    private static function editChanges(mixed $changes): array
    {
        $diff = is_array($changes) ? ($changes['diff'] ?? null) : null;

        return is_array($diff) ? array_values(array_map('strval', $diff)) : [];
    }
}
