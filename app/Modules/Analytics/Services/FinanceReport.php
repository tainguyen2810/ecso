<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\ReturnValues;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tenant finance overview. Separates what is known from what is not:
 *  - ordered / completed revenue are what buyers paid on the marketplace (not money received);
 *  - cost of goods comes from the stock ledger cost captured when goods left the warehouse;
 *  - marketplace fees and the actual payout need settlement data from the marketplace API, which no connector
 *    provides yet, so they are reported as UNKNOWN (null) instead of being estimated;
 *  - other income and expenses are the receipt and payment vouchers whose type counts in profit and loss. They
 *    belong to the whole business, so they are left out (null) when the report is filtered to one shop.
 */
class FinanceReport
{
    /** @return array<string, mixed> */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?int $accountId = null): array
    {
        $orders = fn () => Order::query()->when($accountId, fn ($q) => $q->where('marketplace_account_id', $accountId));

        $placed = $orders()->whereBetween('placed_at', [$from, $to]);
        $ordered = (clone $placed)->where('status', '!=', OrderStatus::CANCELLED)->toBase()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total, COALESCE(SUM(subtotal - discount), 0) as goods')->first();
        $cancelled = (clone $placed)->where('status', OrderStatus::CANCELLED)->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total')->first();

        // Delivered orders, including those with a return request still open: they stay in revenue until the return
        // is approved, and the approved return is then subtracted below.
        $completedStatuses = [OrderStatus::DELIVERED, OrderStatus::COMPLETED, OrderStatus::RETURN_REQUESTED];
        $completed = $orders()->whereIn('status', $completedStatuses)->whereBetween('delivered_at', [$from, $to])->toBase()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total, COALESCE(SUM(subtotal - discount), 0) as goods')->first();

        $cogs = (float) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $this->tenantId())
            ->when($accountId, fn ($q) => $q->where('orders.marketplace_account_id', $accountId))
            ->whereIn('orders.status', $completedStatuses)->whereBetween('orders.delivered_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(order_items.cost_snapshot * order_items.quantity), 0) as cogs')->value('cogs');
        $missingCost = (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $this->tenantId())
            ->when($accountId, fn ($q) => $q->where('orders.marketplace_account_id', $accountId))
            ->whereIn('orders.status', $completedStatuses)->whereBetween('orders.delivered_at', [$from, $to])
            ->whereNull('order_items.cost_snapshot')->where('order_items.mapping_status', '!=', 'service')->count();

        // Returns of the orders counted above only: an order that ended RETURNED or CANCELLED is already out of revenue
        // and cost, so subtracting its return again would count it twice.
        $returns = fn () => DB::table('order_returns as r')->join('orders as ro', 'ro.id', '=', 'r.order_id')
            ->leftJoinSub(ReturnValues::lines(), 'rv', 'rv.order_return_id', '=', 'r.id')
            ->where('r.tenant_id', $this->tenantId())->whereIn('r.status', ReturnValues::COUNTED)->whereIn('ro.status', $completedStatuses)
            ->when($accountId, fn ($q) => $q->where('ro.marketplace_account_id', $accountId));
        $refunds = (float) $returns()->whereBetween('r.created_at', [$from, $to])->selectRaw('COALESCE(SUM('.ReturnValues::valueSql().'), 0) as v')->value('v');
        // Cost of returned goods put back on the shelf, at the cost captured when they shipped.
        $cogsReturned = (float) $returns()->whereBetween('r.inspected_at', [$from, $to])->selectRaw('COALESCE(SUM(rv.cost_back), 0) as v')->value('v');

        $daily = $orders()->whereBetween('placed_at', [$from, $to])->where('status', '!=', OrderStatus::CANCELLED)
            ->selectRaw(ReportRange::day('placed_at', $from).' as day, COUNT(*) as orders, SUM(total) as revenue')->groupBy('day')->orderBy('day')->get()
            ->keyBy('day');
        $series = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $row = $daily->get($d->toDateString());
            $series[] = ['day' => $d->toDateString(), 'orders' => (int) ($row->orders ?? 0), 'revenue' => (float) ($row->revenue ?? 0)];
        }

        // Marketplace shops and the seller's own channels (Phase 9) side by side.
        $byShop = DB::table('orders')
            ->leftJoin('marketplace_accounts', 'marketplace_accounts.id', '=', 'orders.marketplace_account_id')
            ->leftJoin('sales_channels', 'sales_channels.id', '=', 'orders.sales_channel_id')
            ->where('orders.tenant_id', $this->tenantId())->whereBetween('orders.placed_at', [$from, $to])->where('orders.status', '!=', OrderStatus::CANCELLED)
            ->when($accountId, fn ($q) => $q->where('orders.marketplace_account_id', $accountId))
            ->groupBy('orders.marketplace_account_id', 'orders.sales_channel_id', 'marketplace_accounts.shop_name', 'marketplace_accounts.marketplace', 'sales_channels.name')
            ->selectRaw("COALESCE(marketplace_accounts.shop_name, sales_channels.name, 'Khác') as shop, COALESCE(marketplace_accounts.marketplace, 'manual') as marketplace, COUNT(*) as orders, SUM(orders.total) as revenue")
            ->orderByDesc('revenue')->get();

        $completedGoods = (float) $completed->goods;
        $grossProfit = round($completedGoods - $refunds - ($cogs - $cogsReturned), 2);
        $other = $accountId ? null : DB::table('cash_vouchers')
            ->where('tenant_id', $this->tenantId())->whereNull('voided_at')->where('counts_in_pnl', true)->whereBetween('paid_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'receipt' THEN amount ELSE 0 END), 0) as income, COALESCE(SUM(CASE WHEN direction = 'payment' THEN amount ELSE 0 END), 0) as expenses")
            ->first();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'ordered' => ['orders' => (int) $ordered->n, 'revenue' => (float) $ordered->total, 'goods' => (float) $ordered->goods],
            'completed' => ['orders' => (int) $completed->n, 'revenue' => (float) $completed->total, 'goods' => $completedGoods],
            'cancelled' => ['orders' => (int) $cancelled->n, 'revenue' => (float) $cancelled->total],
            'refunds' => $refunds,
            'cogs' => $cogs,
            'cogs_returned' => $cogsReturned,
            'cogs_missing_lines' => $missingCost,
            // Goods value of completed orders minus approved returns minus (cost of goods shipped minus cost of goods
            // returned to stock). Marketplace fees NOT deducted.
            'estimated_gross_profit' => $grossProfit,
            'other_income' => $other ? (float) $other->income : null,
            'other_expenses' => $other ? (float) $other->expenses : null,
            'estimated_profit' => $other ? round($grossProfit + (float) $other->income - (float) $other->expenses, 2) : null,
            'marketplace_fees' => null,
            'payout_received' => null,
            'unknown_reason' => 'Chưa có dữ liệu đối soát/settlement từ API sàn (connector chưa hỗ trợ).',
            'daily' => $series,
            'by_shop' => $byShop->map(fn ($r) => ['shop' => $r->shop, 'marketplace' => $r->marketplace, 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue]),
        ];
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->id();
    }
}
