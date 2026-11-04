<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Support\DataAccess;
use App\Modules\Inventory\Http\Controllers\Concerns\ResolvesLines;
use App\Modules\Inventory\Http\Resources\DocumentResource;
use App\Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    private const COST_FIELDS = ['discount', 'extra_cost', 'extra_cost_note', 'extra_cost_to_supplier', 'vat_in_cost'];

    private const HEADER_FIELDS = ['expected_at', 'note', 'invoice_date', 'reference', 'tags'];

    use ResolvesLines;

    private const DETAIL = ['supplier', 'warehouse', 'creator', 'lines.variant.product', 'receipts.lines', 'receipts.creator', 'payments.creator', 'returns.creator'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...PurchaseOrder::STATUSES, 'open', 'unpaid', 'trading', 'done', 'ordering'])],
            'supplier' => ['nullable', 'string'],
            'warehouse' => ['nullable', 'string'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = PurchaseOrder::query()->with(['supplier', 'warehouse', 'lines', 'creator'])->orderByDesc('id');
        match ($filters['status'] ?? null) {
            null => null,
            'open' => $query->whereIn('status', [PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL]),
            'unpaid' => $query->where('status', '!=', PurchaseOrder::CANCELLED)->whereRaw('received_value - returned_value - paid_amount > 0'),
            // Sapo's tabs: "Đang giao dịch" (goods or money still to come), "Hoàn thành"; "Đặt hàng nhập" = nothing received yet.
            'trading' => $query->whereIn('status', PurchaseOrder::OPEN),
            'done' => $query->whereIn('status', [PurchaseOrder::RECEIVED, PurchaseOrder::CLOSED]),
            'ordering' => $query->whereIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED]),
            default => $query->where('status', $filters['status']),
        };
        if ($ulid = $filters['supplier'] ?? null) {
            $query->whereHas('supplier', fn ($s) => $s->where('ulid', $ulid));
        }
        if ($ulid = $filters['warehouse'] ?? null) {
            $query->whereHas('warehouse', fn ($s) => $s->where('ulid', $ulid));
        }
        if ($from = $filters['created_from'] ?? null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $filters['created_to'] ?? null) {
            $query->where('created_at', '<', now()->parse($to)->addDay());
        }
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")
                ->orWhere('reference', 'like', "%{$q}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$q}%"))
                ->orWhereHas('lines.variant', fn ($v) => $v->where('sku', 'like', "%{$q}%")));
        }

        return PurchaseOrderResource::collection($query->paginate((int) ($filters['per_page'] ?? 20)));
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->load(self::DETAIL));
    }

    /**
     * Like Sapo: "Tạo & chưa nhập" places the order; "Tạo & nhập hàng" also receives everything at once. A payment
     * to the supplier can be recorded in the same step.
     */
    public function store(Request $request, PurchaseService $purchases, SupplierPaymentService $payments): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $data = $request->validate([
            'supplier_id' => ['required', 'string'],
            'warehouse_id' => ['required', 'string'],
            'place' => ['boolean'],
            'receive' => ['boolean'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', 'numeric', 'min:0', 'max:9999999999'],
            'payment.method' => ['required_with:payment', Rule::in(SupplierPayment::METHODS)],
            'payment.reference' => ['nullable', 'string', 'max:100'],
            ...$this->headerRules(),
            ...$this->lineRules(300),
            ...$this->costRules(),
        ]);

        $supplier = $this->findSupplier($data['supplier_id']);
        $po = DB::transaction(function () use ($request, $data, $supplier, $purchases, $payments) {
            $po = $purchases->create([
                'supplier' => $supplier,
                'warehouse' => $this->findWarehouse($data['warehouse_id']),
                'lines' => $this->resolveLines($data['lines']),
                'place' => $request->boolean('place') || $request->boolean('receive'),
                ...array_intersect_key($data, array_flip([...self::HEADER_FIELDS, ...self::COST_FIELDS])),
            ]);
            if ($request->boolean('receive')) {
                $this->authorize('receive', $po);
                $purchases->receive($po, $po->lines()->get()->map(fn ($l) => ['variant_id' => $l->product_variant_id, 'quantity' => $l->quantity])->all());
                $po->refresh();
            }
            if (bccomp((string) ($data['payment']['amount'] ?? 0), '0', 2) > 0) {
                $this->authorize('pay', Supplier::class);
                $payments->record($supplier, ['method' => $data['payment']['method'], 'amount' => $data['payment']['amount'], 'reference' => $data['payment']['reference'] ?? null], $po);
            }

            return $po;
        });

        return (new PurchaseOrderResource($po->fresh(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder, PurchaseService $purchases): PurchaseOrderResource
    {
        $this->authorize('update', $purchaseOrder);
        $data = $request->validate([
            'supplier_id' => ['sometimes', 'string'],
            'warehouse_id' => ['sometimes', 'string'],
            ...$this->headerRules(),
            'lines' => ['sometimes', 'array', 'min:1', 'max:300'],
            'lines.*.variant_id' => ['required', 'string', 'distinct'],
            ...$this->costRules(),
        ]);

        $payload = array_intersect_key($data, array_flip([...self::HEADER_FIELDS, ...self::COST_FIELDS]));
        if (isset($data['supplier_id'])) {
            $payload['supplier'] = $this->findSupplier($data['supplier_id']);
        }
        if (isset($data['warehouse_id'])) {
            $payload['warehouse'] = $this->findWarehouse($data['warehouse_id']);
        }
        if (isset($data['lines'])) {
            $payload['lines'] = $this->resolveLines($data['lines']);
        }

        return new PurchaseOrderResource($purchases->update($purchaseOrder, $payload)->fresh(self::DETAIL));
    }

    public function action(Request $request, PurchaseOrder $purchaseOrder, string $action, PurchaseService $purchases): PurchaseOrderResource
    {
        $this->authorize('update', $purchaseOrder);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        $po = match ($action) {
            'place' => $purchases->place($purchaseOrder),
            'close' => $purchases->close($purchaseOrder, $reason),
            'cancel' => $purchases->cancel($purchaseOrder, $reason),
            default => abort(404),
        };

        return new PurchaseOrderResource($po->fresh(self::DETAIL));
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, PurchaseService $purchases): JsonResponse
    {
        $this->authorize('receive', $purchaseOrder);
        app(DataAccess::class)->assertWarehouse($purchaseOrder->warehouse_id);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->lineRules(300),
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        // Only purchasing managers may change the price on arrival.
        if (! $request->user()->can('update', $purchaseOrder)) {
            $data['lines'] = array_map(fn ($l) => array_diff_key($l, ['unit_cost' => true]), $data['lines']);
        }

        $receipt = $purchases->receive($purchaseOrder, $this->resolveLines($data['lines']), $data['note'] ?? null);

        return response()->json([
            'data' => new PurchaseOrderResource($purchaseOrder->fresh(self::DETAIL)),
            'receipt' => new DocumentResource($receipt->load(['warehouse', 'creator', 'lines.variant.product'])),
        ], 201);
    }

    private function findSupplier(string $ulid): Supplier
    {
        return Supplier::query()->where('ulid', $ulid)->first()
            ?? throw ValidationException::withMessages(['supplier_id' => 'Nhà cung cấp không tồn tại.']);
    }

    /** @return array<string, array<int, mixed>> */
    private function headerRules(): array
    {
        return [
            'expected_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'invoice_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'extra_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'extra_cost_note' => ['nullable', 'string', 'max:255'],
            'extra_cost_to_supplier' => ['boolean'],
            'vat_in_cost' => ['boolean'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function costRules(): array
    {
        return [
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
