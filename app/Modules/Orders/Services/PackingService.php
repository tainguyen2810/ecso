<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\PackScanError;
use App\Shared\Support\ReportRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Scan-to-pack (C1). The packer scans the order code or tracking number to open an order that is waiting to be
 * packed, then scans each item's barcode or SKU. The screen counts the scans; this service finds the order, lists
 * what must go into the parcel, logs wrong scans and finishes the order (status PACKED, same as the "Đóng gói"
 * action). A parcel short of items is only finished with a reason, kept as an order note.
 */
class PackingService
{
    private const STATUS_LABELS = [
        OrderStatus::UNPAID => 'Chờ thanh toán',
        OrderStatus::PENDING_CONFIRMATION => 'Chờ xác nhận',
        OrderStatus::PACKED => 'Chờ bàn giao',
        OrderStatus::HANDED_OVER => 'Đã bàn giao',
        OrderStatus::IN_TRANSIT => 'Đang giao',
        OrderStatus::DELIVERED => 'Đã giao',
        OrderStatus::COMPLETED => 'Hoàn tất',
        OrderStatus::CANCELLED => 'Đã hủy',
        OrderStatus::FAILED_DELIVERY => 'Giao thất bại',
        OrderStatus::RETURN_REQUESTED => 'Yêu cầu trả',
        OrderStatus::RETURNED => 'Đã hoàn',
    ];

    public function __construct(private readonly OrderActions $actions, private readonly AuditLogger $audit) {}

    /** The order behind a scanned code: our order code, the marketplace's order id or the tracking number. */
    public function find(string $code): Order
    {
        $code = trim($code);
        $matches = OrderQuery::visible(Order::query())
            ->where(fn ($q) => $q->where('external_order_id', $code)->orWhere('tracking_number', $code)->orWhere('ulid', $code))
            ->orderByRaw('status = ? desc', [OrderStatus::READY_TO_PACK])
            ->latest('id')
            ->limit(5)
            ->get();
        $order = $matches->first();
        if ($order === null) {
            throw ValidationException::withMessages(['code' => "Không tìm thấy đơn có mã {$code}."]);
        }
        if ($order->status !== OrderStatus::READY_TO_PACK) {
            $label = self::STATUS_LABELS[$order->status] ?? $order->status;
            throw ValidationException::withMessages(['code' => "Đơn {$order->external_order_id} đang ở trạng thái \"{$label}\", không phải chờ đóng gói."]);
        }

        return $order;
    }

    /**
     * What goes into the parcel: one line per order item (service lines left out), with the codes that count as a
     * scan of it (barcode and SKU).
     *
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        $order->loadMissing(['account', 'channel', 'items.variant.product.images']);
        $lines = $order->items
            ->filter(fn (OrderItem $i) => $i->mapping_status !== OrderItem::SERVICE)
            ->map(function (OrderItem $i) {
                $variant = $i->variant;
                $sku = $variant->sku ?? $i->seller_sku;
                $quantity = max(0, $i->quantity - (int) $i->refunded_quantity);

                return [
                    'id' => (string) $i->id,
                    'sku' => $sku,
                    'barcode' => $variant?->barcode,
                    'name' => $i->name,
                    'variant_name' => $i->variant_name,
                    'quantity' => $quantity,
                    'location' => $variant?->location,
                    'image' => $variant?->product?->images->first()?->url(),
                    'codes' => array_values(array_unique(array_filter([
                        $variant?->barcode ? mb_strtolower($variant->barcode) : null,
                        $sku ? mb_strtolower($sku) : null,
                        $i->seller_sku ? mb_strtolower($i->seller_sku) : null,
                    ]))),
                ];
            })
            ->filter(fn ($l) => $l['quantity'] > 0)
            ->values();

        return [
            'id' => $order->ulid,
            'external_order_id' => $order->external_order_id,
            'marketplace' => $order->marketplace,
            'shop' => $order->account->shop_name ?? $order->channel?->name,
            'buyer_name' => $order->buyer_name,
            'carrier' => $order->shipping_carrier,
            'tracking_number' => $order->tracking_number,
            'note' => $order->buyer_note,
            'is_cod' => (bool) $order->is_cod,
            'total' => $order->total,
            'lines' => $lines,
        ];
    }

    public function logError(Order $order, string $code, string $kind): void
    {
        PackScanError::query()->create(['order_id' => $order->id, 'user_id' => Auth::id(), 'code' => mb_substr(trim($code), 0, 100), 'kind' => $kind]);
    }

    /**
     * Marks the order packed. $scanned holds the count per line id; a line short of its quantity needs $reason.
     *
     * @param  array<string, int>  $scanned
     */
    public function finish(Order $order, array $scanned, ?string $reason): Order
    {
        $lines = collect($this->present($order)['lines']);
        $short = $lines
            ->filter(fn ($l) => (int) ($scanned[$l['id']] ?? 0) < $l['quantity'])
            ->map(fn ($l) => ($l['sku'] ?? $l['name']).' '.min((int) ($scanned[$l['id']] ?? 0), $l['quantity']).'/'.$l['quantity']);
        $reason = filled($reason) ? trim((string) $reason) : null;
        if ($short->isNotEmpty() && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Đơn còn thiếu hàng: ghi lý do đóng thiếu, hoặc quét đủ rồi hoàn tất.']);
        }

        $order = $this->actions->perform($order, 'pack');

        if ($short->isNotEmpty()) {
            $this->actions->addNote($order, 'Đóng thiếu hàng: '.$short->implode(', ').'. Lý do: '.$reason);
            $this->audit->record('order.packed_short', $order, ['order' => $order->external_order_id, 'short' => $short->values()->all(), 'reason' => $reason]);
        }

        return $order;
    }

    /**
     * Today's figures for the side panel, on the shop's clock.
     *
     * @return array{packed_today: int, errors_today: int, remaining: int, recent: Collection<int, array<string, mixed>>}
     */
    public function stats(): array
    {
        $since = ReportRange::now()->startOfDay()->utc();
        $packed = OrderStatusHistory::query()
            ->where('to_status', OrderStatus::PACKED)
            ->where('created_at', '>=', $since);
        $recent = (clone $packed)->with('actor:id,name')->latest('id')->limit(8)->get();
        $orders = Order::query()->withCount('items')->whereIn('id', $recent->pluck('order_id'))->get()->keyBy('id');

        return [
            'packed_today' => (clone $packed)->distinct()->count('order_id'),
            'errors_today' => PackScanError::query()->where('created_at', '>=', $since)->count(),
            'remaining' => OrderQuery::visible(Order::query())->where('status', OrderStatus::READY_TO_PACK)->count(),
            'recent' => $recent->filter(fn (OrderStatusHistory $h) => $orders->has($h->order_id))->map(fn (OrderStatusHistory $h) => [
                'id' => $orders[$h->order_id]->ulid,
                'external_order_id' => $orders[$h->order_id]->external_order_id,
                'items' => (int) $orders[$h->order_id]->items_count,
                'by' => $h->actor?->name,
                'at' => $h->created_at->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * Pick list (phiếu nhặt hàng): the selected orders' items summed per SKU, sorted by shelf location so the picker
     * walks the shelves once.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, array<string, mixed>>
     */
    public function pickList(Collection $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if ($item->mapping_status === OrderItem::SERVICE) {
                    continue;
                }
                $quantity = max(0, $item->quantity - (int) $item->refunded_quantity);
                if ($quantity === 0) {
                    continue;
                }
                $variant = $item->variant;
                $key = $variant ? 'v'.$variant->id : 's'.mb_strtolower(($item->seller_sku ?? '').'|'.$item->name.'|'.$item->variant_name);
                $rows[$key] ??= [
                    'sku' => $variant->sku ?? $item->seller_sku,
                    'barcode' => $variant?->barcode,
                    'name' => $variant ? $variant->displayName() : trim($item->name.($item->variant_name ? ' - '.$item->variant_name : '')),
                    'location' => $variant?->location,
                    'quantity' => 0,
                    'orders' => [],
                ];
                $rows[$key]['quantity'] += $quantity;
                $code = $order->external_order_id;
                $rows[$key]['orders'][$code] = ($rows[$key]['orders'][$code] ?? 0) + $quantity;
            }
        }

        return collect($rows)
            ->map(fn ($r) => [...$r, 'orders' => collect($r['orders'])->map(fn ($q, $code) => ['code' => (string) $code, 'quantity' => $q])->values()->all()])
            ->sortBy(fn ($r) => [$r['location'] === null ? 1 : 0, mb_strtolower((string) $r['location']), mb_strtolower((string) $r['sku'])])
            ->values()
            ->all();
    }
}
