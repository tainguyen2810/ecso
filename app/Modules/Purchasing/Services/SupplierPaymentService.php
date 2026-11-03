<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Payments to suppliers. Against an order they cannot exceed what is owed on it; on account they can (prepayment). */
class SupplierPaymentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{method: string, amount: string|int|float, reference?: string|null, note?: string|null, paid_at?: string|null}  $data
     */
    public function record(Supplier $supplier, array $data, ?PurchaseOrder $po = null): SupplierPayment
    {
        if ($po && $po->supplier_id !== $supplier->id) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Đơn nhập không thuộc nhà cung cấp này.']);
        }

        return DB::transaction(function () use ($supplier, $data, $po) {
            $amount = bcadd((string) $data['amount'], '0', 2);
            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền phải lớn hơn 0.']);
            }
            if ($po) {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
                // An order not yet (fully) received can be paid up to its total: a prepayment to the supplier.
                $due = $po->status === PurchaseOrder::CANCELLED ? $po->balanceDue() : $this->payable($po);
                if (bccomp($amount, $due, 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Số tiền vượt quá còn nợ của đơn ('.number_format((float) $due, 0, ',', '.').' ₫).']);
                }
            }

            $payment = new SupplierPayment([
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $po?->id,
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);
            $payment->forceFill(['created_by' => Auth::id()])->save();
            if ($po) {
                $this->recompute($po);
            }
            $this->audit->record('purchase.paid', $payment, ['supplier' => $supplier->code, 'amount' => $amount, 'order' => $po?->number]);

            return $payment;
        });
    }

    public function void(SupplierPayment $payment, string $reason): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->voided_at) {
                throw ValidationException::withMessages(['payment' => 'Phiếu chi đã hủy.']);
            }
            $payment->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason])->save();
            if ($payment->purchase_order_id) {
                $this->recompute(PurchaseOrder::query()->lockForUpdate()->findOrFail($payment->purchase_order_id));
            }
            $this->audit->record('purchase.payment_voided', $payment, ['amount' => $payment->amount, 'reason' => $reason]);

            return $payment;
        });
    }

    private function payable(PurchaseOrder $po): string
    {
        $value = bccomp((string) $po->received_value, (string) $po->total, 2) >= 0 || in_array($po->status, [PurchaseOrder::RECEIVED, PurchaseOrder::CLOSED], true)
            ? (string) $po->received_value
            : (string) $po->total;

        return bcsub(bcsub($value, (string) $po->returned_value, 2), (string) $po->paid_amount, 2);
    }

    private function recompute(PurchaseOrder $po): void
    {
        $paid = (string) SupplierPayment::query()->where('purchase_order_id', $po->id)->whereNull('voided_at')->sum('amount');
        $po->forceFill(['paid_amount' => bcadd($paid, '0', 2)])->save();
    }
}
