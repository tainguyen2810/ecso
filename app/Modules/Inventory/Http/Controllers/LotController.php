<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Support\DataAccess;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\LotLedger;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lots and expiry dates of a product (tab "Lô & hạn sử dụng"): what each lot holds in each warehouse, and the
 * fixes staff make after receiving: split "Chưa rõ lô" into real lots, correct dates, move a lot that may no
 * longer be sold to the defective-goods warehouse.
 */
class LotController extends Controller
{
    public function index(Product $product, LotLedger $lots, DataAccess $access): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);
        $variants = $product->variants()->get()->keyBy('id');
        $allowed = $access->warehouseIds();
        $today = LotLedger::today();
        $rules = $variants->isEmpty() ? null : $lots->rules((int) $variants->keys()->first());

        $rows = StockLot::query()->with('warehouse')->whereIn('product_variant_id', $variants->keys())
            ->when($allowed !== null, fn ($q) => $q->whereIn('warehouse_id', $allowed))
            ->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '>', 0)->orWhere('updated_at', '>=', now()->subDays(90)))
            ->orderByRaw("lot_no <> '', expiry_date IS NULL, expiry_date, warehouse_id, id")
            ->limit(500)->get()
            ->map(function (StockLot $lot) use ($variants, $lots, $today, $rules) {
                $variant = $variants[$lot->product_variant_id];
                $daysLeft = $lot->expiry_date ? (int) Carbon::parse($today)->diffInDays($lot->expiry_date, false) : null;
                $blocked = $lots->isBlocked($lot);

                return [
                    'id' => $lot->id,
                    'variant' => ['id' => $variant->ulid, 'sku' => $variant->sku, 'name' => $variant->name],
                    'warehouse' => ['id' => $lot->warehouse->ulid, 'name' => $lot->warehouse->name, 'purpose' => $lot->warehouse->purpose ?? null],
                    'lot_no' => $lot->lot_no === StockLot::UNKNOWN ? null : $lot->lot_no,
                    'mfg_date' => $lot->mfg_date?->toDateString(),
                    'expiry_date' => $lot->expiry_date?->toDateString(),
                    'days_left' => $daysLeft,
                    'on_hand' => $lot->on_hand,
                    'reserved' => $lot->reserved,
                    'sellable' => $blocked ? 0 : $lot->free(),
                    'status' => match (true) {
                        $lot->on_hand <= 0 => 'empty',
                        $daysLeft !== null && $daysLeft < 0 => 'expired',
                        $blocked => 'blocked',
                        $daysLeft !== null && $rules && $daysLeft <= $rules['warn'] => 'expiring',
                        $lot->lot_no === StockLot::UNKNOWN => 'unknown',
                        default => 'ok',
                    },
                    'received_at' => $lot->first_received_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => $rows,
            'rules' => $rules ? ['track_lots' => $rules['track'], 'warn_days' => $rules['warn'], 'block_days' => $rules['block'], 'shelf_life_months' => $rules['shelf']] : null,
        ]);
    }

    /** Moves free stock of a lot to another lot (new or existing) of the same SKU and warehouse. */
    public function move(Request $request, StockLot $lot, InventoryService $inventory, DataAccess $access): JsonResponse
    {
        $this->authorize('adjust', StockBalance::class);
        $access->assertWarehouse($lot->warehouse_id);
        $data = $request->validate([
            'lot_no' => ['required', 'string', 'max:64'],
            'mfg_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $target = $this->stock(fn () => $inventory->moveLot($lot, $data, (int) $data['quantity'], 'lot-move:'.$lot->id.':'.Str::uuid(), "Chuyển {$data['quantity']} từ lô {$lot->label()} sang lô {$data['lot_no']}"));

        return response()->json(['data' => ['id' => $target->id, 'lot_no' => $target->lot_no]]);
    }

    public function update(Request $request, StockLot $lot, InventoryService $inventory, DataAccess $access): JsonResponse
    {
        $this->authorize('adjust', StockBalance::class);
        $access->assertWarehouse($lot->warehouse_id);
        $data = $request->validate(['mfg_date' => ['nullable', 'date'], 'expiry_date' => ['required', 'date']]);
        $this->stock(fn () => $inventory->redateLot($lot, $data['mfg_date'] ?? null, $data['expiry_date'], 'lot-redate:'.$lot->id.':'.Str::uuid()));

        return response()->json(['data' => ['id' => $lot->id]]);
    }

    /**
     * Moves what is left of a lot to the defective-goods warehouse (expired goods to write off): a transfer that is
     * dispatched and received at once, so the ledger shows where the goods went.
     */
    public function toDefective(Request $request, StockLot $lot, InventoryDocumentService $documents, WarehouseService $warehouses, DataAccess $access): JsonResponse
    {
        $this->authorize('transfer', StockBalance::class);
        $access->assertWarehouse($lot->warehouse_id);
        $data = $request->validate(['quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'note' => ['nullable', 'string', 'max:255']]);
        $quantity = (int) ($data['quantity'] ?? $lot->free());
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Lô không còn hàng chưa giữ cho đơn.']);
        }
        $from = Warehouse::query()->findOrFail($lot->warehouse_id);
        $to = $warehouses->defective();
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['quantity' => 'Lô đã ở kho hàng lỗi.']);
        }

        $transfer = $this->stock(fn () => DB::transaction(function () use ($documents, $from, $to, $lot, $quantity, $data) {
            $lines = [['variant_id' => $lot->product_variant_id, 'quantity' => $quantity, 'lots' => [['lot_no' => $lot->lot_no, 'quantity' => $quantity]]]];
            $note = trim("Lô {$lot->label()}".($lot->expiry_date ? ' HSD '.$lot->expiry_date->format('d/m/Y') : '').' chuyển sang kho hàng lỗi. '.($data['note'] ?? ''));
            $transfer = $documents->createTransfer($from, $to, $lines, $note);
            $documents->dispatchTransfer($transfer);

            return $documents->receiveTransfer($transfer->refresh());
        }));

        return response()->json(['data' => ['transfer' => $transfer->ulid, 'number' => $transfer->number]]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function stock(callable $work): mixed
    {
        try {
            return $work();
        } catch (InvalidStockOperation $e) {
            throw ValidationException::withMessages(['lot' => $e->getMessage()]);
        }
    }
}
