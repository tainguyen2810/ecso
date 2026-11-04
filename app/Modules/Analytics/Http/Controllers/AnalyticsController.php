<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\BusinessReports;
use App\Modules\Analytics\Services\FinanceDetails;
use App\Modules\Analytics\Services\FinanceReport;
use App\Modules\Analytics\Services\SalesReport;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function finance(Request $request, TenantContext $context, FinanceReport $report): JsonResponse
    {
        abort_unless($context->can('finance.view'), 403);
        [$from, $to] = $this->range($request);

        $data = $report->build($from, $to, $this->financeScope($request));
        // Cost of goods and profit are for members with finance.profit only.
        $data['can_view_profit'] = $context->can('finance.profit');

        return response()->json(['data' => $data['can_view_profit'] ? $data : self::withoutProfit($data)]);
    }

    /** The orders or returns behind one figure of the finance page, on the same filters. */
    public function financeDetails(Request $request, TenantContext $context, FinanceDetails $details): JsonResponse
    {
        abort_unless($context->can('finance.view'), 403);
        $metric = $request->validate(['metric' => ['required', Rule::in(FinanceDetails::METRICS)]])['metric'];
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $details->build($metric, $from, $to, $this->financeScope($request), $context->can('finance.profit'))]);
    }

    /**
     * The shop or own sales channel the finance report is scoped to: ?shop=<ulid> (or the older ?account=<ulid>). A
     * ulid of another tenant is not found.
     *
     * @return array{account?: int, channel?: int}|null
     */
    private function financeScope(Request $request): ?array
    {
        $ulid = $request->query('shop') ?? $request->query('account');
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }
        $account = MarketplaceAccount::query()->where('ulid', $ulid)->value('id');

        return $account ? ['account' => $account] : ['channel' => SalesChannel::query()->where('ulid', $ulid)->firstOrFail()->id];
    }

    /** The finance report without cost of goods, fees and anything derived from them. */
    public static function withoutProfit(array $data): array
    {
        foreach (['cogs', 'cogs_returned', 'cogs_missing_lines', 'estimated_gross_profit', 'contribution_profit', 'selling_expenses', 'stock_loss',
            'operating_expenses', 'other_income', 'other_expenses', 'estimated_profit'] as $key) {
            $data[$key] = null;
        }
        foreach (['estimated_gross_profit', 'contribution_profit', 'estimated_profit'] as $key) {
            $data['previous'][$key] = null;
        }
        $data['daily'] = array_map(fn ($d) => array_merge($d, ['cogs' => null, 'gross_profit' => null]), $data['daily']);
        $data['by_shop'] = array_map(fn ($r) => array_merge($r, ['cogs' => null, 'gross_profit' => null, 'profit' => null, 'margin' => null]), $data['by_shop']);

        return $data;
    }

    public function bestSellers(Request $request, TenantContext $context, SalesReport $report): JsonResponse
    {
        abort_unless($context->can('reports.view'), 403);
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $report->bestSellers($from, $to)]);
    }

    /** Advanced report: available when the plan's entitlements include reports.advanced (not by plan name). */
    public function slowMovers(Request $request, TenantContext $context, SalesReport $report): JsonResponse
    {
        abort_unless($context->can('reports.view'), 403);
        if (! $context->entitlements()->allows('reports.advanced')) {
            return response()->json(['message' => 'Báo cáo hàng bán chậm có trong gói có “Báo cáo nâng cao”.', 'code' => 'FEATURE_NOT_IN_PLAN', 'feature' => 'reports.advanced'], 403);
        }
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:365'], 'max_units' => ['nullable', 'integer', 'min:0', 'max:1000']]);

        return response()->json(['data' => $report->slowMovers((int) ($data['days'] ?? 30), (int) ($data['max_units'] ?? 0))]);
    }

    /** Sales and gross profit across all channels, by day, channel, staff, customer or warehouse. */
    public function sales(Request $request, TenantContext $context, BusinessReports $reports): JsonResponse
    {
        abort_unless($context->can('reports.view'), 403);
        [$from, $to] = $this->range($request);
        $by = $request->validate(['by' => ['nullable', Rule::in(['day', 'channel', 'staff', 'customer', 'warehouse'])]])['by'] ?? 'day';

        return response()->json(['data' => $reports->sales($from, $to, $by)]);
    }

    /** Stock in-out-balance per SKU (xuất nhập tồn). */
    public function stockLedger(Request $request, TenantContext $context, BusinessReports $reports): JsonResponse
    {
        abort_unless($context->can('reports.view') && $context->can('inventory.view'), 403);
        if ($request->boolean('export')) {
            config(['ecso.reports.row_limit' => (int) config('ecso.reports.export_row_limit', 50000)]);
            app(AuditLogger::class)->record('report.exported', null, ['report' => 'stock-ledger', 'filters' => $request->except('export')]);
        }
        [$from, $to] = $this->range($request);
        $data = $request->validate(['warehouse' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:100']]);
        $warehouse = ($data['warehouse'] ?? null) ? Warehouse::query()->where('ulid', $data['warehouse'])->firstOrFail()->id : null;

        return response()->json(['data' => $reports->stockLedger($from, $to, $warehouse, $data['q'] ?? null)]);
    }

    /** Money in and out of own-channel sales and supplier payments (sổ quỹ). */
    public function cashbook(Request $request, TenantContext $context, BusinessReports $reports): JsonResponse
    {
        abort_unless($context->can('finance.view'), 403);
        [$from, $to] = $this->range($request);
        $method = $request->validate(['method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'card', 'ewallet', 'cod', 'other'])]])['method'] ?? null;

        return response()->json(['data' => $reports->cashbook($from, $to, $method)]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        return ReportRange::fromRequest($request);
    }
}
