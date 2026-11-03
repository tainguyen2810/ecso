<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\BusinessReports;
use App\Modules\Analytics\Services\FinanceReport;
use App\Modules\Analytics\Services\SalesReport;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Tenancy\Support\TenantContext;
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
        $account = $request->query('account') ? MarketplaceAccount::query()->where('ulid', $request->query('account'))->firstOrFail()->id : null;

        return response()->json(['data' => $report->build($from, $to, $account)]);
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

    /** Sales and gross profit across all channels, by day, channel or staff. */
    public function sales(Request $request, TenantContext $context, BusinessReports $reports): JsonResponse
    {
        abort_unless($context->can('reports.view'), 403);
        [$from, $to] = $this->range($request);
        $by = $request->validate(['by' => ['nullable', Rule::in(['day', 'channel', 'staff'])]])['by'] ?? 'day';

        return response()->json(['data' => $reports->sales($from, $to, $by)]);
    }

    /** Stock in-out-balance per SKU (xuất nhập tồn). */
    public function stockLedger(Request $request, TenantContext $context, BusinessReports $reports): JsonResponse
    {
        abort_unless($context->can('reports.view') && $context->can('inventory.view'), 403);
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
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now()->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Khoảng thời gian tối đa 1 năm.');

        return [$from, $to];
    }
}
