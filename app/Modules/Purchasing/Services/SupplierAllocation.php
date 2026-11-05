<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What is still owed on each purchase order of a supplier, once money paid on account is counted.
 *
 * A payment made against an order is that order's. Money paid on account (prepayments, payments without an order),
 * less what the supplier paid back, plus what was overpaid on an order, is a credit spread over the orders still owed,
 * oldest received first. Nothing is stored: the figures follow the payments as they are recorded or voided.
 *
 * An order is due on its own due date, else on its received date plus the supplier's payment terms; without either it
 * is never overdue.
 */
class SupplierAllocation
{
    /**
     * @param  list<int>  $supplierIds
     * @return array<int, array{credit_left: float, open: float, overdue: float, orders: array<int, array{open: float, credit: float, due_date: string|null, overdue: bool}>}>
     */
    public function forSuppliers(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }
        $terms = DB::table('suppliers')->whereIn('id', $supplierIds)->pluck('payment_terms_days', 'id');
        $onAccount = SupplierPayment::query()->whereIn('supplier_id', $supplierIds)->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('purchase_order_id')->orWhere('kind', SupplierPayment::REFUND))
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM('.SupplierPayment::SIGNED_SQL.') as total')->pluck('total', 'supplier_id');
        $orders = PurchaseOrder::query()->whereIn('supplier_id', $supplierIds)->where('status', '!=', PurchaseOrder::CANCELLED)
            ->orderByRaw('COALESCE(received_at, ordered_at, created_at)')->orderBy('id')
            ->get(['id', 'supplier_id', 'received_value', 'returned_value', 'paid_amount', 'received_at', 'ordered_at', 'created_at', 'due_date']);
        $today = ReportRange::now()->toDateString();

        $out = [];
        foreach ($supplierIds as $sid) {
            $mine = $orders->where('supplier_id', $sid);
            $credit = (float) ($onAccount[$sid] ?? 0);
            // Overpaid orders add to the credit.
            foreach ($mine as $po) {
                $balance = (float) $po->received_value - (float) $po->returned_value - (float) $po->paid_amount;
                if ($balance < 0) {
                    $credit += -$balance;
                }
            }
            $rows = [];
            $open = 0.0;
            $overdue = 0.0;
            foreach ($mine as $po) {
                $balance = max(0.0, (float) $po->received_value - (float) $po->returned_value - (float) $po->paid_amount);
                $applied = min($balance, max(0.0, $credit));
                $credit -= $applied;
                $left = round($balance - $applied, 2);
                $due = $po->due_date?->toDateString();
                if ($due === null && $terms[$sid] !== null && $po->received_at !== null) {
                    $due = CarbonImmutable::parse($po->received_at)->setTimezone(ReportRange::timezone())->addDays((int) $terms[$sid])->toDateString();
                }
                $late = $left > 0 && $due !== null && $due < $today;
                $rows[$po->id] = ['open' => $left, 'credit' => round($applied, 2), 'due_date' => $due, 'overdue' => $late];
                $open += $left;
                $overdue += $late ? $left : 0;
            }
            $out[$sid] = ['credit_left' => round(max(0.0, $credit), 2), 'open' => round($open, 2), 'overdue' => round($overdue, 2), 'orders' => $rows];
        }

        return $out;
    }
}
