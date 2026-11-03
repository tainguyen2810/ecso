<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Http\Resources\SupplierPaymentResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierPaymentController extends Controller
{
    public function store(Request $request, Supplier $supplier, SupplierPaymentService $payments): JsonResponse
    {
        $this->authorize('pay', Supplier::class);
        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'string'],
            'method' => ['required', Rule::in(SupplierPayment::METHODS)],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $po = null;
        if ($data['purchase_order_id'] ?? null) {
            $po = PurchaseOrder::query()->where('ulid', $data['purchase_order_id'])->first()
                ?? throw ValidationException::withMessages(['purchase_order_id' => 'Đơn nhập không tồn tại.']);
        }

        $payment = $payments->record($supplier, $data, $po);

        return (new SupplierPaymentResource($payment->load(['creator', 'order'])))->response()->setStatusCode(201);
    }

    public function void(Request $request, SupplierPayment $supplierPayment, SupplierPaymentService $payments): SupplierPaymentResource
    {
        $this->authorize('pay', Supplier::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new SupplierPaymentResource($payments->void($supplierPayment, $data['reason'])->load(['creator', 'order']));
    }
}
