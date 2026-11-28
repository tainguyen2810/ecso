<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Shared\Support\ReportRange;
use Illuminate\Support\Facades\DB;

/**
 * Figures above the two purchase lists (mẫu 28/11): "Đặt hàng nhập" (orders waiting for goods, late, due this week,
 * this month's orders, delivery calendar) and "Nhập hàng" (goods received this month, orders waiting to be received,
 * still owed to suppliers with what is overdue, paid this month). Counts for the tabs of both lists.
 */
class PurchaseOrderSummary
{
    public function __construct(private readonly SupplierAllocation $allocations) {}

    /** @return array<string, mixed> */
    public function ordering(): array
    {
        $today = ReportRange::now()->startOfDay();
        $month = $today->startOfMonth();
        $previous = $month->subMonth();
        $waiting = PurchaseOrder::query()->where('status', PurchaseOrder::ORDERED);
        $value = fn ($q) => (float) (clone $q)->sum('total');

        $late = (clone $waiting)->whereNotNull('expected_at')->where('expected_at', '<', $today->toDateString());
        $soon = (clone $waiting)->whereBetween('expected_at', [$today->toDateString(), $today->addDays(7)->toDateString()]);
        $thisMonth = PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED)->where('created_at', '>=', $month);
        $lastMonth = PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED)->where('created_at', '>=', $previous)->where('created_at', '<', $month);

        $calendar = (clone $waiting)->whereNotNull('expected_at')->with(['supplier:id,name', 'lines:id,purchase_order_id,quantity'])
            ->orderBy('expected_at')->limit(5)->get()
            ->map(fn (PurchaseOrder $po) => [
                'id' => $po->ulid,
                'number' => $po->number,
                'supplier' => $po->supplier?->name,
                'expected_at' => $po->expected_at?->toDateString(),
                'total' => (string) $po->total,
                'quantity' => (int) $po->lines->sum('quantity'),
                'late' => $po->expected_at !== null && $po->expected_at->toDateString() < $today->toDateString(),
            ])->all();

        return [
            'counts' => [
                'all' => PurchaseOrder::query()->whereIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED])->count(),
                'draft' => PurchaseOrder::query()->where('status', PurchaseOrder::DRAFT)->count(),
                'ordered' => (clone $waiting)->count(),
            ],
            'waiting' => [
                'count' => (clone $waiting)->count(),
                'value' => $value($waiting),
                'quantity' => (int) DB::table('purchase_order_lines')->whereIn('purchase_order_id', (clone $waiting)->select('id'))->sum('quantity'),
            ],
            'late' => ['count' => (clone $late)->count(), 'value' => $value($late)],
            'soon' => ['count' => (clone $soon)->count(), 'value' => $value($soon)],
            'month' => [
                'count' => (clone $thisMonth)->count(),
                'value' => $value($thisMonth),
                'previous' => $value($lastMonth),
                'suppliers' => (clone $thisMonth)->distinct()->count('supplier_id'),
            ],
            'calendar' => $calendar,
        ];
    }

    /** @return array<string, mixed> */
    public function receiving(): array
    {
        $now = ReportRange::now();
        $month = $now->startOfMonth();
        $previous = $month->subMonth();
        $received = fn ($from, $to) => StockReceipt::query()->where('source', StockReceipt::SOURCE_PURCHASE)
            ->where('created_at', '>=', $from)->when($to, fn ($q) => $q->where('created_at', '<', $to));
        $value = fn ($q) => DB::table('stock_receipt_lines')->whereIn('stock_receipt_id', (clone $q)->select('id'))
            ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as value, COALESCE(SUM(quantity), 0) as quantity')->first();
        $thisMonth = $value($received($month, null));
        $lastMonth = $value($received($previous, $month));

        $pending = PurchaseOrder::query()->whereIn('status', [PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL]);

        // Owed: every supplier with something received and not fully paid, money paid on account spread first.
        $supplierIds = PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED)
            ->whereRaw('received_value - returned_value - paid_amount > 0')->distinct()->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();
        $owed = ['value' => 0.0, 'orders' => 0, 'overdue' => 0.0, 'overdue_orders' => 0, 'suppliers' => 0];
        foreach ($this->allocations->forSuppliers($supplierIds) as $supplier) {
            $owed['value'] += $supplier['open'];
            $owed['overdue'] += $supplier['overdue'];
            $owed['suppliers'] += $supplier['open'] > 0 ? 1 : 0;
            foreach ($supplier['orders'] as $order) {
                $owed['orders'] += $order['open'] > 0 ? 1 : 0;
                $owed['overdue_orders'] += $order['overdue'] ? 1 : 0;
            }
        }

        $paid = SupplierPayment::query()->whereNull('voided_at')->where('kind', SupplierPayment::PAYMENT)->where('paid_at', '>=', $month)
            ->groupBy('method')->selectRaw('method, SUM(amount) as amount')->pluck('amount', 'method')->map(fn ($v) => (float) $v);

        return [
            'counts' => [
                'all' => PurchaseOrder::query()->count(),
                'trading' => PurchaseOrder::query()->whereIn('status', PurchaseOrder::OPEN)->count(),
                'done' => PurchaseOrder::query()->whereIn('status', [PurchaseOrder::RECEIVED, PurchaseOrder::CLOSED])->count(),
                'unpaid' => PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED)->whereRaw('received_value - returned_value - paid_amount > 0')->count(),
                'cancelled' => PurchaseOrder::query()->where('status', PurchaseOrder::CANCELLED)->count(),
            ],
            'month' => [
                'value' => (float) $thisMonth->value,
                'quantity' => (int) $thisMonth->quantity,
                'receipts' => $received($month, null)->count(),
                'previous' => (float) $lastMonth->value,
            ],
            'pending' => ['count' => (clone $pending)->count(), 'value' => (float) (clone $pending)->sum(DB::raw('total - received_value'))],
            'owed' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $owed),
            'paid' => ['value' => (float) $paid->sum(), 'by_method' => $paid->all()],
        ];
    }
}
