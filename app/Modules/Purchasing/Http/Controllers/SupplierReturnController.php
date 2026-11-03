<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Controllers\Concerns\ResolvesLines;
use App\Modules\Purchasing\Http\Resources\SupplierReturnResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Services\SupplierReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SupplierReturnController extends Controller
{
    use ResolvesLines;

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierReturn::class);

        return SupplierReturnResource::collection(
            SupplierReturn::query()->with(['supplier', 'order', 'warehouse', 'creator'])->orderByDesc('id')->paginate(20)
        );
    }

    public function show(SupplierReturn $supplierReturn): SupplierReturnResource
    {
        $this->authorize('view', $supplierReturn);

        return new SupplierReturnResource($supplierReturn->load(['supplier', 'order', 'warehouse', 'creator', 'lines.variant.product']));
    }

    public function store(Request $request, SupplierReturnService $returns): JsonResponse
    {
        $this->authorize('create', SupplierReturn::class);
        $data = $request->validate([
            'supplier_id' => ['required_without:purchase_order_id', 'nullable', 'string'],
            'purchase_order_id' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
            ...$this->lineRules(300),
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        $po = null;
        if ($data['purchase_order_id'] ?? null) {
            $po = PurchaseOrder::query()->where('ulid', $data['purchase_order_id'])->first()
                ?? throw ValidationException::withMessages(['purchase_order_id' => 'Đơn nhập không tồn tại.']);
        }
        $supplier = $po ? Supplier::query()->findOrFail($po->supplier_id)
            : (Supplier::query()->where('ulid', $data['supplier_id'])->first() ?? throw ValidationException::withMessages(['supplier_id' => 'Nhà cung cấp không tồn tại.']));
        $warehouse = ($data['warehouse_id'] ?? null) ? $this->findWarehouse($data['warehouse_id']) : $po?->warehouse;
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'Chọn kho xuất trả.']);
        }

        $return = $returns->create($supplier, $warehouse, $this->resolveLines($data['lines']), $po, $data['reason'] ?? null);

        return (new SupplierReturnResource($return->load(['supplier', 'order', 'warehouse', 'creator', 'lines.variant.product'])))->response()->setStatusCode(201);
    }
}
