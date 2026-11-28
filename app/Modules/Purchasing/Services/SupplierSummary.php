<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Shared\Support\ReportRange;
use Illuminate\Support\Facades\DB;

/**
 * Figures above "Nhà cung cấp" (mẫu 28/11): still owed (money paid on account spread first) and what is overdue,
 * goods received this month and from whom, suppliers in business, the next purchase orders to pay, counts per tab.
 */
class SupplierSummary
{
    public function __construct(private readonly SupplierAllocation $allocations) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $today = ReportRange::now()->toDateString();
        $ids = PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED)
            ->whereRaw('received_value - returned_value - paid_amount > 0')->distinct()->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();
        $allocated = $this->allocations->forSuppliers($ids);

        $owed = ['value' => 0.0, 'orders' => 0, 'suppliers' => 0, 'overdue' => 0.0, 'overdue_orders' => 0, 'overdue_suppliers' => 0, 'oldest_days' => 0];
        $due = [];
        foreach ($allocated as $sid => $s) {
            $owed['value'] += $s['open'];
            $owed['overdue'] += $s['overdue'];
            $owed['suppliers'] += $s['open'] > 0 ? 1 : 0;
            $owed['overdue_suppliers'] += $s['overdue'] > 0 ? 1 : 0;
            foreach ($s['orders'] as $orderId => $o) {
                if ($o['open'] <= 0) {
                    continue;
                }
                $owed['orders']++;
                if ($o['overdue']) {
                    $owed['overdue_orders']++;
                    $owed['oldest_days'] = max($owed['oldest_days'], (int) ReportRange::date($o['due_date'])->diffInDays(ReportRange::date($today)));
                }
                $due[] = ['order_id' => $orderId, 'supplier_id' => $sid, 'open' => $o['open'], 'due_date' => $o['due_date'], 'overdue' => $o['overdue']];
            }
        }
        // Next to pay: overdue and dated first, by due date.
        usort($due, fn ($a, $b) => [$a['due_date'] === null, $a['due_date']] <=> [$b['due_date'] === null, $b['due_date']]);
        $due = array_slice($due, 0, 5);
        $orders = PurchaseOrder::query()->whereKey(array_column($due, 'order_id'))->with('supplier:id,ulid,name')->get(['id', 'ulid', 'number', 'supplier_id'])->keyBy('id');
        $due = array_map(fn ($d) => [
            'id' => $orders[$d['order_id']]?->ulid,
            'number' => $orders[$d['order_id']]?->number,
            'supplier' => $orders[$d['order_id']]?->supplier?->name,
            'supplier_id' => $orders[$d['order_id']]?->supplier?->ulid,
            'open' => $d['open'],
            'due_date' => $d['due_date'],
            'overdue' => $d['overdue'],
            'days' => $d['due_date'] ? (int) ReportRange::date($today)->diffInDays(ReportRange::date($d['due_date']), false) : null,
        ], $due);

        // Received this month, per supplier (purchase receipts at their purchase price).
        $month = ReportRange::now()->startOfMonth();
        $top = DB::table('stock_receipt_lines as l')
            ->join('stock_receipts as r', 'r.id', '=', 'l.stock_receipt_id')
            ->join('purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->whereIn('r.id', StockReceipt::query()->where('source', StockReceipt::SOURCE_PURCHASE)->where('created_at', '>=', $month)->select('id'))
            ->groupBy('s.id', 's.ulid', 's.name')
            ->selectRaw('s.ulid as id, s.name, SUM(l.quantity * l.unit_cost) as value, COUNT(DISTINCT r.id) as receipts')
            ->orderByDesc('value')->get();
        $monthValue = (float) $top->sum('value');

        $active = Supplier::query()->where('is_active', true);

        return [
            'counts' => [
                'all' => Supplier::query()->count(),
                'active' => (clone $active)->count(),
                'inactive' => Supplier::query()->where('is_active', false)->count(),
                'debt' => DB::query()->fromSub(Supplier::query()->withBalance()->having('debt', '>', 0), 'owing')->count(),
            ],
            'owed' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $owed),
            'month' => [
                'value' => round($monthValue, 2),
                'receipts' => (int) $top->sum('receipts'),
                'suppliers' => $top->count(),
                'top' => $top->take(5)->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'value' => round((float) $r->value, 2)])->values()->all(),
            ],
            'due' => $due,
        ];
    }
}
