<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\BusinessReports;
use App\Modules\Analytics\Services\LedgerReports;
use App\Modules\Analytics\Services\OrderReports;
use App\Modules\Analytics\Services\StockReports;
use App\Modules\Analytics\Services\TradeReports;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The report centre: one summary per report group, and the detail reports behind each card. */
class ReportsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** Headline figures for the report centre; each group is present only when the user may see it. */
    public function overview(Request $request, BusinessReports $business, TradeReports $trade, StockReports $stock, LedgerReports $ledger, OrderReports $orders): JsonResponse
    {
        $this->allow('reports.view');
        [$from, $to] = $this->range($request);

        $sales = $business->sales($from, $to, 'channel');
        $returns = $trade->returns($from, $to);
        $customers = $trade->customers($from, $to);
        $data = [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'sales' => [...$this->withoutProfit($sales['totals']), 'top_channels' => array_map(fn ($r) => $this->withoutProfit($r), array_slice($sales['rows'], 0, 3))],
            'returns' => $returns['totals'],
            'customers' => $customers['summary'],
            'delivery' => $orders->delivery($from, $to),
        ];
        if ($this->context->can('finance.view')) {
            $pl = collect($ledger->profitLoss($from, $to)['lines'])->keyBy('key');
            $profit = $this->context->can('finance.profit');
            $data['finance'] = [
                'revenue' => $pl['revenue']['current'],
                'profit' => $profit ? $pl['profit']['current'] : null,
                'profit_change' => $profit ? $pl['profit']['change'] : null,
                'payments' => $trade->payments($from, $to)['totals'],
                'receivable' => $ledger->receivables()['totals'],
            ];
        }
        if ($this->context->can('purchasing.view')) {
            $data['purchases'] = $trade->purchases($from, $to)['totals'];
            $data['payable'] = $ledger->payables()['totals'];
        }
        if ($this->context->can('inventory.view')) {
            $data['stock'] = [...$stock->onHand($to)['totals'], 'low' => $stock->lowStock()['totals'], 'over' => $stock->overStock()['totals']];
        }

        return response()->json(['data' => $data]);
    }

    public function productSales(Request $request, TradeReports $trade): JsonResponse
    {
        $this->allow('reports.view');
        $this->exporting($request, 'product-sales');
        [$from, $to] = $this->range($request);
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return response()->json(['data' => $trade->salesByProduct($from, $to, $q)]);
    }

    public function returns(Request $request, TradeReports $trade): JsonResponse
    {
        $this->allow('reports.view');
        $this->exporting($request, 'returns');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $trade->returns($from, $to, $this->by($request, ['order', 'product']))]);
    }

    public function payments(Request $request, TradeReports $trade): JsonResponse
    {
        $this->allow('finance.view');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $trade->payments($from, $to, $this->by($request, ['method', 'staff', 'day', 'warehouse']))]);
    }

    public function purchases(Request $request, TradeReports $trade): JsonResponse
    {
        $this->allow('purchasing.view');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $trade->purchases($from, $to, $this->by($request, ['supplier', 'product', 'day']))]);
    }

    public function supplierPayments(Request $request, TradeReports $trade): JsonResponse
    {
        $this->allow('purchasing.view');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $trade->supplierPayments($from, $to, $this->by($request, ['method', 'staff', 'supplier', 'day']))]);
    }

    public function adjustments(Request $request, StockReports $stock): JsonResponse
    {
        $this->allow('inventory.view');
        [$from, $to] = $this->range($request);
        $warehouse = $request->validate(['warehouse' => ['nullable', 'string']])['warehouse'] ?? null;

        return response()->json(['data' => $stock->adjustments($from, $to, $this->by($request, ['document', 'product', 'reason']), $this->warehouse($warehouse))]);
    }

    public function customers(Request $request, TradeReports $trade): JsonResponse
    {
        $this->allow('reports.view');
        $this->exporting($request, 'customers');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $trade->customers($from, $to)]);
    }

    public function stockOnHand(Request $request, StockReports $stock): JsonResponse
    {
        $this->allow('inventory.view');
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'warehouse' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
            'zero' => ['nullable', 'boolean'],
        ]);
        $date = isset($data['date']) ? ReportRange::date($data['date']) : ReportRange::now();

        return response()->json(['data' => $stock->onHand($date, $this->warehouse($data['warehouse'] ?? null), $data['q'] ?? null, $request->boolean('zero'))]);
    }

    public function lowStock(Request $request, StockReports $stock): JsonResponse
    {
        $this->allow('inventory.view');
        $warehouse = $request->validate(['warehouse' => ['nullable', 'string']])['warehouse'] ?? null;

        return response()->json(['data' => $stock->lowStock($this->warehouse($warehouse))]);
    }

    public function overStock(Request $request, StockReports $stock): JsonResponse
    {
        $this->allow('inventory.view');
        $warehouse = $request->validate(['warehouse' => ['nullable', 'string']])['warehouse'] ?? null;

        return response()->json(['data' => $stock->overStock($this->warehouse($warehouse))]);
    }

    /** Sapo "Thống kê theo đơn hàng". */
    public function orders(Request $request, OrderReports $orders): JsonResponse
    {
        $this->allow('reports.view');
        $this->exporting($request, 'orders');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $orders->byOrder($from, $to, $this->orderFilters($request))]);
    }

    /** Sapo "Báo cáo bán hàng chi tiết": one row per order line. */
    public function salesLines(Request $request, OrderReports $orders): JsonResponse
    {
        $this->allow('reports.view');
        $this->exporting($request, 'sales-lines');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $orders->lines($from, $to, $this->orderFilters($request))]);
    }

    public function stockDetail(Request $request, OrderReports $orders): JsonResponse
    {
        $this->allow('inventory.view');
        $this->exporting($request, 'stock-detail');
        $data = $request->validate(['warehouse' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $orders->stockDetail($this->warehouse($data['warehouse'] ?? null), $data['q'] ?? null)]);
    }

    /** @return array{q: string|null, channel: int|null, warehouse: int|null} */
    private function orderFilters(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'channel' => ['nullable', 'string'], 'warehouse' => ['nullable', 'string']]);

        return [
            'q' => $data['q'] ?? null,
            'channel' => isset($data['channel']) ? SalesChannel::query()->where('ulid', $data['channel'])->firstOrFail()->id : null,
            'warehouse' => $this->warehouse($data['warehouse'] ?? null),
        ];
    }

    public function profitLoss(Request $request, LedgerReports $ledger): JsonResponse
    {
        $this->allow('finance.profit');
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $ledger->profitLoss($from, $to)]);
    }

    public function receivables(LedgerReports $ledger): JsonResponse
    {
        $this->allow('finance.view');

        return response()->json(['data' => $ledger->receivables()]);
    }

    public function payables(LedgerReports $ledger): JsonResponse
    {
        $this->allow('purchasing.view');

        return response()->json(['data' => $ledger->payables()]);
    }

    /**
     * Cost of goods and profit figures are for members with finance.profit only.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withoutProfit(array $row): array
    {
        if ($this->context->can('finance.profit')) {
            return $row;
        }

        return array_merge($row, array_fill_keys(array_intersect(array_keys($row), ['cogs', 'gross_profit', 'margin', 'missing_cost_lines']), null));
    }

    /**
     * "Xuất CSV" of a report whose table stops at the row limit asks again with export=1: every row of the same filter
     * is returned, up to the export limit, and the export is written to the audit log (who exported what, when).
     */
    private function exporting(Request $request, string $report): void
    {
        if (! $request->boolean('export')) {
            return;
        }
        $limit = (int) config('ecso.reports.export_row_limit', 50000);
        config(['ecso.reports.row_limit' => $limit, 'ecso.reports.customer_row_limit' => $limit]);
        app(AuditLogger::class)->record('report.exported', null, ['report' => $report, 'filters' => $request->except('export')]);
    }

    private function allow(string $permission): void
    {
        abort_unless($this->context->can($permission), 403);
    }

    /** @param  array<int, string>  $allowed */
    private function by(Request $request, array $allowed): string
    {
        return $request->validate(['by' => ['nullable', Rule::in($allowed)]])['by'] ?? $allowed[0];
    }

    private function warehouse(?string $ulid): ?int
    {
        return $ulid ? Warehouse::query()->where('ulid', $ulid)->firstOrFail()->id : null;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        return ReportRange::fromRequest($request);
    }
}
