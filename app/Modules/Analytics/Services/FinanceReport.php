<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tenant finance overview. Separates what is known from what is not:
 *  - ordered / completed revenue are what buyers paid on the marketplace (not money received);
 *  - cost of goods comes from the stock ledger cost captured when goods left the warehouse;
 *  - marketplace fees and the actual payout need settlement data from the marketplace API, which no connector
 *    provides yet, so they are reported as UNKNOWN (null) instead of being estimated.
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

        $completedStatuses = [OrderStatus::DELIVERED, OrderStatus::COMPLETED];
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
            ->whereNull('order_items.cost_snapshot')->count();

        $refunds = (float) OrderReturn::query()->where('status', '!=', OrderReturn::REJECTED)->whereBetween('created_at', [$from, $to])
            ->when($accountId, fn ($q) => $q->whereIn('order_id', $orders()->select('id')))->sum('refund_amount');

        $daily = $orders()->whereBetween('placed_at', [$from, $to])->where('status', '!=', OrderStatus::CANCELLED)
            ->selectRaw('DATE(placed_at) as day, COUNT(*) as orders, SUM(total) as revenue')->groupBy('day')->orderBy('day')->get()
            ->keyBy('day');
        $series = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $row = $daily->get($d->toDateString());
            $series[] = ['day' => $d->toDateString(), 'orders' => (int) ($row->orders ?? 0), 'revenue' => (float) ($row->revenue ?? 0)];
        }

        $byShop = DB::table('orders')->join('marketplace_accounts', 'marketplace_accounts.id', '=', 'orders.marketplace_account_id')
            ->where('orders.tenant_id', $this->tenantId())->whereBetween('orders.placed_at', [$from, $to])->where('orders.status', '!=', OrderStatus::CANCELLED)
            ->groupBy('marketplace_accounts.id', 'marketplace_accounts.shop_name', 'marketplace_accounts.marketplace')
            ->selectRaw('marketplace_accounts.shop_name as shop, marketplace_accounts.marketplace, COUNT(*) as orders, SUM(orders.total) as revenue')
            ->orderByDesc('revenue')->get();

        $completedGoods = (float) $completed->goods;

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'ordered' => ['orders' => (int) $ordered->n, 'revenue' => (float) $ordered->total, 'goods' => (float) $ordered->goods],
            'completed' => ['orders' => (int) $completed->n, 'revenue' => (float) $completed->total, 'goods' => $completedGoods],
            'cancelled' => ['orders' => (int) $cancelled->n, 'revenue' => (float) $cancelled->total],
            'refunds' => $refunds,
            'cogs' => $cogs,
            'cogs_missing_lines' => $missingCost,
            // Goods value of completed orders minus cost of goods minus refunds. Marketplace fees NOT deducted.
            'estimated_gross_profit' => round($completedGoods - $cogs - $refunds, 2),
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
