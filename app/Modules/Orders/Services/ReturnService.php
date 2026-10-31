<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns and refunds (ORDER-LIFECYCLE §5). Rules:
 *  - a refund never adds stock;
 *  - stock comes back only at inspection, for the quantity inspected as sellable, into the chosen warehouse,
 *    through InventoryService (RETURN_RESTOCK, idempotency key per return).
 */
class ReturnService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReservationLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Opens (once) the return for goods that left the warehouse and are coming back: cancel after handover or
     * failed delivery. Runs inside the order transaction.
     */
    public function openAutomatic(Order $order, string $type, string $sourceKey, string $reason): OrderReturn
    {
        $existing = OrderReturn::query()->where('order_id', $order->id)->where('source_key', $sourceKey)->first();
        if ($existing) {
            return $existing;
        }

        $items = $order->items()->get();
        $shipped = $this->ledger->snapshot(Order::STOCK_REFERENCE, $items->pluck('id')->all());

        $lines = $items->map(fn (OrderItem $item) => [
            'item' => $item,
            // Only what actually left the warehouse can come back into it.
            'quantity' => ($shipped[$item->id]['status'] ?? null) === 'consumed' ? $shipped[$item->id]['quantity'] : 0,
        ])->filter(fn ($l) => $l['quantity'] > 0)->values();

        return $this->persist($order, [
            'type' => $type,
            'status' => OrderReturn::IN_TRANSIT,
            'source_key' => $sourceKey,
            'reason' => $reason,
            'warehouse_id' => $order->warehouse_id,
        ], $lines->all());
    }

    /**
     * A return or refund recorded by a user (or later imported from the marketplace).
     *
     * @param  array{type: string, reason?: string|null, refund_amount?: string|float|null, lines?: array<int, array{order_item_id: int, quantity: int}>}  $data
     */
    public function create(Order $order, array $data): OrderReturn
    {
        return DB::transaction(function () use ($order, $data) {
            Order::query()->whereKey($order->id)->lockForUpdate()->first();
            $items = $order->items()->get()->keyBy('id');
            $alreadyReturned = OrderReturnLine::query()
                ->whereIn('order_return_id', OrderReturn::query()->where('order_id', $order->id)->where('status', '!=', OrderReturn::REJECTED)->select('id'))
                ->selectRaw('order_item_id, SUM(quantity) as qty')->groupBy('order_item_id')->pluck('qty', 'order_item_id');

            $lines = [];
            foreach ($data['lines'] ?? [] as $i => $line) {
                $item = $items[$line['order_item_id']] ?? throw ValidationException::withMessages(["lines.{$i}.order_item_id" => 'Sản phẩm không thuộc đơn này.']);
                $left = $item->quantity - (int) ($alreadyReturned[$item->id] ?? 0);
                if ($line['quantity'] > $left) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => "Chỉ còn {$left} sản phẩm có thể trả."]);
                }
                $lines[] = ['item' => $item, 'quantity' => (int) $line['quantity']];
            }

            if ($data['type'] !== OrderReturn::TYPE_REFUND_ONLY && $lines === []) {
                throw ValidationException::withMessages(['lines' => 'Chọn ít nhất một sản phẩm trả về.']);
            }

            $return = $this->persist($order, [
                'type' => $data['type'],
                'status' => OrderReturn::REQUESTED,
                'reason' => $data['reason'] ?? null,
                'refund_amount' => $data['refund_amount'] ?? null,
                'warehouse_id' => $order->warehouse_id,
                'created_by' => Auth::id(),
            ], $data['type'] === OrderReturn::TYPE_REFUND_ONLY ? [] : $lines);

            $this->audit->record('return.created', $return, ['order' => $order->external_order_id, 'type' => $return->type]);

            return $return;
        });
    }

    public function move(OrderReturn $return, string $to, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $to, $note) {
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! in_array($to, OrderReturn::FLOW[$return->status] ?? [], true) || $to === OrderReturn::INSPECTED) {
                throw ValidationException::withMessages(['status' => 'Không thể chuyển phiếu trả hàng sang trạng thái này.']);
            }
            if (! $return->expectsGoods() && in_array($to, [OrderReturn::IN_TRANSIT, OrderReturn::RECEIVED], true)) {
                throw ValidationException::withMessages(['status' => 'Hoàn tiền không kèm trả hàng: không có hàng để nhận.']);
            }
            if ($return->expectsGoods() && $to === OrderReturn::CLOSED && $return->inspected_at === null) {
                throw ValidationException::withMessages(['status' => 'Cần kiểm hàng trước khi đóng phiếu.']);
            }

            $return->forceFill([
                'status' => $to,
                'received_at' => $to === OrderReturn::RECEIVED ? now() : $return->received_at,
                'closed_at' => $to === OrderReturn::CLOSED ? now() : $return->closed_at,
                'note' => $note ?? $return->note,
            ])->save();

            // A refund-only request goes straight from approved to closed: there is nothing to receive.
            $this->audit->record('return.'.$to, $return, ['number' => $return->number]);

            return $return;
        });
    }

    /**
     * Records what came back and adds the sellable quantity to stock.
     *
     * @param  array<int, array{line_id: int, received_qty: int, sellable_qty: int, damaged_qty: int}>  $lines
     */
    public function inspect(OrderReturn $return, Warehouse $warehouse, array $lines, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $warehouse, $lines, $note) {
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! $return->expectsGoods() || $return->status !== OrderReturn::RECEIVED) {
                throw ValidationException::withMessages(['status' => 'Chỉ kiểm được phiếu đã nhận hàng.']);
            }

            $byId = $return->lines()->get()->keyBy('id');
            $restock = [];
            foreach ($lines as $i => $input) {
                $line = $byId[$input['line_id']] ?? throw ValidationException::withMessages(["lines.{$i}.line_id" => 'Dòng không thuộc phiếu này.']);
                if ($input['received_qty'] > $line->quantity) {
                    throw ValidationException::withMessages(["lines.{$i}.received_qty" => "Không được nhận quá {$line->quantity}."]);
                }
                if ($input['received_qty'] !== $input['sellable_qty'] + $input['damaged_qty']) {
                    throw ValidationException::withMessages(["lines.{$i}.sellable_qty" => 'Bán được + hỏng phải bằng số đã nhận.']);
                }
                if ($input['sellable_qty'] > 0 && $line->product_variant_id === null) {
                    throw ValidationException::withMessages(["lines.{$i}.sellable_qty" => 'Sản phẩm chưa liên kết SKU nội bộ, không thể nhập kho.']);
                }

                $line->forceFill(['received_qty' => $input['received_qty'], 'sellable_qty' => $input['sellable_qty'], 'damaged_qty' => $input['damaged_qty']])->save();
                if ($input['sellable_qty'] > 0) {
                    $restock[] = ['variant_id' => $line->product_variant_id, 'quantity' => $input['sellable_qty']];
                }
            }

            if ($restock !== []) {
                $this->inventory->restockReturn($warehouse, $restock, "restock:return:{$return->id}", $return, "Hàng hoàn {$return->number}");
            }

            $return->forceFill([
                'status' => OrderReturn::INSPECTED,
                'warehouse_id' => $warehouse->id,
                'inspected_at' => now(),
                'inspected_by' => Auth::id(),
                'note' => $note ?? $return->note,
            ])->save();

            $this->audit->record('return.inspected', $return, ['number' => $return->number, 'restocked' => array_sum(array_column($restock, 'quantity'))]);

            return $return;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{item: OrderItem, quantity: int}>  $lines
     */
    private function persist(Order $order, array $attributes, array $lines): OrderReturn
    {
        $return = new OrderReturn;
        $return->forceFill([...$attributes, 'order_id' => $order->id, 'number' => DocumentNumber::next('RET')])->save();

        foreach ($lines as $line) {
            $return->lines()->create([
                'order_item_id' => $line['item']->id,
                'product_variant_id' => $line['item']->product_variant_id,
                'quantity' => $line['quantity'],
            ]);
        }

        return $return;
    }
}
