<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Support\DataAccess;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\ReturnSync;
use App\Modules\Orders\Http\Resources\ReturnResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnLine;
use App\Modules\Orders\Services\ReturnService;
use App\Modules\Sales\Models\OrderPayment;
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
            // pending_receipt: goods expected back but not received yet (the "Chưa nhận hàng" tab).
            'status' => ['nullable', Rule::in([...array_keys(OrderReturn::FLOW), 'pending_receipt'])],
            'type' => ['nullable', Rule::in(OrderReturn::TYPES)],
            // marketplace: requests read from Shopee / TikTok.
            'source' => ['nullable', Rule::in(['marketplace'])],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        return ReturnResource::collection(OrderReturn::query()->with(['order.customer', 'warehouse', 'exchangeOrder'])->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'pending_receipt'
                ? $q->where('type', '!=', OrderReturn::TYPE_REFUND_ONLY)->whereIn('status', [OrderReturn::REQUESTED, OrderReturn::APPROVED, OrderReturn::IN_TRANSIT])
                : $q->where('status', $s))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['source'] ?? null, fn ($q) => $q->whereNotNull('external_return_id'))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")
                ->orWhere('return_tracking_number', $term)->orWhere('external_return_id', $term)
                ->orWhereHas('order', fn ($o) => $o->where('external_order_id', 'like', "%{$term}%"))))
            ->orderByRaw("FIELD(status, 'received', 'in_transit', 'requested', 'approved', 'inspected', 'closed', 'rejected')")
            ->orderByDesc('id')->paginate(30))->additional(['meta' => ['summary' => $this->summary()]]);
    }

    /**
     * Khách trả hàng figures (mẫu 28/11): returns on the way back and waiting for inspection, money refunded this month,
     * how much of what came back could be sold again, reasons and most returned products over 30 days, tab counts.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $since = now()->subDays(30);
        $pending = OrderReturn::query()->where('type', '!=', OrderReturn::TYPE_REFUND_ONLY)->whereIn('status', [OrderReturn::REQUESTED, OrderReturn::APPROVED, OrderReturn::IN_TRANSIT]);
        $refunded = OrderReturn::query()->where('status', '!=', OrderReturn::REJECTED)->where('refund_amount', '>', 0)->where('created_at', '>=', now()->startOfMonth());
        $checked = OrderReturnLine::query()->whereIn('order_return_id', OrderReturn::query()->whereIn('status', [OrderReturn::INSPECTED, OrderReturn::CLOSED])->where('created_at', '>=', now()->subDays(90))->select('id'))
            ->selectRaw('COALESCE(SUM(received_qty), 0) as received, COALESCE(SUM(sellable_qty), 0) as sellable, COALESCE(SUM(damaged_qty), 0) as damaged')->first();
        $recent = OrderReturn::query()->where('created_at', '>=', $since)->where('status', '!=', OrderReturn::REJECTED);
        $total = (clone $recent)->count();

        return [
            'counts' => OrderReturn::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all() + ['all' => OrderReturn::query()->count(), 'pending_receipt' => (clone $pending)->count()],
            'on_the_way' => (clone $pending)->count(),
            'on_the_way_late' => (clone $pending)->where('created_at', '<', now()->subDays(7))->count(),
            'to_inspect' => OrderReturn::query()->where('status', OrderReturn::RECEIVED)->count(),
            'to_inspect_items' => (int) OrderReturnLine::query()->whereIn('order_return_id', OrderReturn::query()->where('status', OrderReturn::RECEIVED)->select('id'))->sum('quantity'),
            'refunded' => round((float) (clone $refunded)->sum('refund_amount')),
            'refunded_count' => (clone $refunded)->count(),
            'resellable_pct' => (int) $checked->received > 0 ? (int) round(100 * (int) $checked->sellable / (int) $checked->received) : null,
            'damaged' => (int) $checked->damaged,
            'reasons_total' => $total,
            'reasons' => (clone $recent)->selectRaw("COALESCE(NULLIF(TRIM(reason), ''), 'Không ghi lý do') as label, COUNT(*) as n")->groupBy('label')->orderByDesc('n')->limit(5)->get()
                ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->n])->values(),
            'products' => OrderReturnLine::query()->whereIn('order_return_id', (clone $recent)->select('id'))
                ->join('product_variants', 'product_variants.id', '=', 'order_return_lines.product_variant_id')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->groupBy('product_variants.id', 'products.name', 'product_variants.name')
                ->selectRaw('products.name as product, product_variants.name as variant, SUM(order_return_lines.quantity) as qty')
                ->orderByDesc('qty')->limit(5)->get()
                ->map(fn ($r) => ['name' => $r->product, 'variant' => $r->variant, 'quantity' => (int) $r->qty])->values(),
        ];
    }

    public function show(OrderReturn $return): ReturnResource
    {
        $this->authorize('view', $return);

        return new ReturnResource($return->load(['order', 'exchangeOrder', 'warehouse', 'damagedWarehouse', 'lines.item', 'lines.variant', 'creator', 'inspector']));
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

    public function refund(Request $request, OrderReturn $return, ReturnService $returns): ReturnResource
    {
        $this->authorize('pay', Order::class);
        $data = $request->validate([
            'method' => ['required', Rule::in(OrderPayment::METHODS)],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return new ReturnResource($returns->refund($return, $data)->load(['order', 'exchangeOrder', 'warehouse', 'lines.item', 'lines.variant', 'creator', 'inspector']));
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
        app(DataAccess::class)->assertWarehouse($warehouse);

        return new ReturnResource($returns->inspect($return, $warehouse, $data['lines'], $data['note'] ?? null)->load(['order', 'warehouse', 'damagedWarehouse', 'lines.item', 'lines.variant', 'inspector']));
    }

    /** Đợt 6 "Quét hàng hoàn": the scanned tracking number (or order code) opens its return, marked received. */
    public function scan(Request $request, ReturnService $returns): ReturnResource
    {
        $this->authorize('inspect', OrderReturn::class);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:64']])['code'];
        $return = $returns->receiveByCode($code);
        abort_if($return === null, 404, "Không có phiếu hoàn nào chờ nhận với mã {$code}.");

        return new ReturnResource($return->load(['order', 'warehouse', 'lines.item', 'lines.variant']));
    }

    /** Đợt 6: reads the marketplaces' return requests now ("Lấy yêu cầu từ sàn"). */
    public function syncMarketplace(ReturnSync $sync): JsonResponse
    {
        $this->authorize('viewAny', OrderReturn::class);
        $totals = ['read' => 0, 'created' => 0, 'failed' => []];
        foreach (MarketplaceAccount::query()->where('status', MarketplaceAccount::CONNECTED)->get() as $account) {
            if (! $sync->supports($account) || $account->setupPending()) {
                continue;
            }
            try {
                $c = $sync->syncAccount($account);
                $totals['read'] += $c['read'];
                $totals['created'] += $c['created'];
            } catch (ConnectorException $e) {
                $totals['failed'][] = "{$account->shop_name}: {$e->getMessage()}";
            }
        }

        return response()->json(['data' => $totals]);
    }
}
