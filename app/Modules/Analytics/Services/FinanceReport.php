<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\ProfitFigures;
use App\Modules\Analytics\Support\RevenuePolicy;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tenant finance overview. Profit figures come from ProfitFigures, the same source as the profit and loss report.
 * It keeps apart what is known from what is estimated:
 *  - ordered revenue is what buyers ordered; recognised (completed) revenue follows RevenuePolicy (marketplaces at
 *    completion, own channels at delivery). Neither is money received;
 *  - marketplace fees are CONFIRMED by an imported settlement, ESTIMATED from the shop's fee table, or UNKNOWN;
 *  - the payout of a settlement is money received for revenue already counted, never new revenue;
 *  - other income and expenses belong to the whole business and are null when the report is scoped to one shop.
 */
class FinanceReport
{
    public function __construct(private readonly ProfitFigures $profit, private readonly TenantContext $context) {}

    /**
     * @param  array{account?: int, channel?: int}|null  $scope
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?array $scope = null): array
    {
        $scope = array_filter($scope ?? []) ?: null;
        [$prevFrom, $prevTo] = $this->previous($from, $to);

        $placed = fn () => $this->profit->scoped(DB::table('orders as o')->where('o.tenant_id', $this->tenant())->whereBetween('o.placed_at', [$from, $to]), $scope);
        $ordered = $placed()->where('o.status', '!=', OrderStatus::CANCELLED)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(o.total), 0) as total, COALESCE(SUM('.ProfitFigures::netGoodsSql().'), 0) as goods')->first();
        $cancelled = $placed()->where('o.status', OrderStatus::CANCELLED)->selectRaw('COUNT(*) as n, COALESCE(SUM(o.total), 0) as total')->first();

        $completed = $this->profit->recognized($from, $to, $scope)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(o.total), 0) as total, COALESCE(SUM('.ProfitFigures::netGoodsSql().'), 0) as goods, COALESCE(SUM(o.shipping_fee), 0) as shipping')->first();
        $awaiting = $this->profit->scoped(RevenuePolicy::awaitingCompletion(DB::table('orders as o')->where('o.tenant_id', $this->tenant()), 'o', $from, $to), $scope)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(o.total), 0) as total, COALESCE(SUM('.ProfitFigures::netGoodsSql().'), 0) as goods')->first();

        $p = $this->profit->period($from, $to, $scope);
        $prev = $this->profit->period($prevFrom, $prevTo, $scope);
        $payout = $this->payout($from, $to, $scope);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_range' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'scoped' => $scope !== null,
            'ordered' => ['orders' => (int) $ordered->n, 'revenue' => (float) $ordered->total, 'goods' => (float) $ordered->goods],
            // Recognised revenue. goods = goods after discounts (net of shipping); shipping = charged to the buyer, apart.
            'completed' => ['orders' => (int) $completed->n, 'revenue' => (float) $completed->total, 'goods' => (float) $completed->goods, 'shipping' => (float) $completed->shipping],
            // Marketplace orders delivered in the period, not completed by the marketplace yet: provisional, not in profit.
            'awaiting_completion' => ['orders' => (int) $awaiting->n, 'revenue' => (float) $awaiting->total, 'goods' => (float) $awaiting->goods],
            'cancelled' => ['orders' => (int) $cancelled->n, 'revenue' => (float) $cancelled->total],
            'refunds' => $p['returns'],
            'net_revenue' => $p['revenue'],
            'cogs' => $p['cogs_out'],
            'cogs_returned' => $p['cogs_back'],
            'cogs_missing_lines' => $p['missing_cost_lines'],
            'estimated_gross_profit' => $p['gross_profit'],
            'fees' => [
                'total' => $p['marketplace_fees'],
                'confirmed' => $p['fees_confirmed'],
                'confirmed_orders' => $p['fees_confirmed_orders'],
                'estimated' => $p['fees_estimated'],
                'estimated_orders' => $p['fees_estimated_orders'],
                'unknown_orders' => $p['fees_unknown_orders'],
            ],
            'marketplace_fees' => $p['marketplace_fees'],
            'payout' => $payout,
            'payout_received' => $payout['received'],
            'selling_expenses' => $p['selling_expenses'],
            'contribution_profit' => $p['contribution_profit'],
            'stock_loss' => $p['stock_loss'],
            'operating_expenses' => $p['operating_expenses'],
            'other_income' => $p['other_income'],
            'other_expenses' => $p['other_expenses'],
            'estimated_profit' => $p['profit'],
            'previous' => [
                'orders' => $prev['orders'],
                'net_revenue' => $prev['revenue'],
                'estimated_gross_profit' => $prev['gross_profit'],
                'contribution_profit' => $prev['contribution_profit'],
                'estimated_profit' => $prev['profit'],
            ],
            'daily' => $this->daily($from, $to, $scope),
            'by_shop' => $scope ? [] : $this->byShop($from, $to, $prevFrom, $prevTo),
            'shops' => $this->shops(),
        ];
    }

    /** The period of the same length just before [from, to]. @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function previous(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        return [$from->subDays($days)->startOfDay(), $from->subDay()->endOfDay()];
    }

    /**
     * Money the marketplaces paid out for orders recognised in the period (settlement net amount), and the marketplace
     * orders still waiting for their settlement. A payout is cash for revenue already counted, not new revenue.
     *
     * @return array{received: float|null, received_orders: int, pending_orders: int, pending_expected: float|null}
     */
    private function payout(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        $row = ProfitFigures::withFees($this->profit->recognized($from, $to, $scope))->whereRaw(ProfitFigures::isMarketplaceSql())
            ->selectRaw('COALESCE(SUM(s.net_amount), 0) as received, COUNT(s.id) as received_orders, '
                .'COALESCE(SUM(CASE WHEN s.id IS NULL THEN 1 ELSE 0 END), 0) as pending_orders, '
                .'SUM(CASE WHEN s.id IS NULL THEN o.subtotal - o.discount - ('.ProfitFigures::estimatedFeeSql().') END) as pending_expected, '
                .'COALESCE(SUM('.ProfitFigures::unknownFeeSql().'), 0) as pending_unknown')
            ->first();

        return [
            'received' => (int) $row->received_orders > 0 ? round((float) $row->received, 2) : null,
            'received_orders' => (int) $row->received_orders,
            'pending_orders' => (int) $row->pending_orders,
            // Expected from the fee table; null when any pending order has no fee table (amount unknown).
            'pending_expected' => (int) $row->pending_unknown > 0 || $row->pending_expected === null ? null : round((float) $row->pending_expected, 2),
        ];
    }

    /** Orders placed per day, and goods, cost and gross profit (before returns) of orders recognised per day. */
    private function daily(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        $placed = $this->profit->scoped(DB::table('orders as o')->where('o.tenant_id', $this->tenant()), $scope)
            ->whereBetween('o.placed_at', [$from, $to])->where('o.status', '!=', OrderStatus::CANCELLED)
            ->selectRaw(ReportRange::day('o.placed_at', $from).' as day, COUNT(*) as orders, SUM(o.total) as revenue')->groupBy('day')->get()->keyBy('day');
        $day = ReportRange::day(RevenuePolicy::dateSql('o'), $from);
        $cost = DB::table('order_items')->selectRaw('order_id, SUM(cost_snapshot * quantity) as cogs')->groupBy('order_id');
        $recognized = $this->profit->recognized($from, $to, $scope)->leftJoinSub($cost, 'c', 'c.order_id', '=', 'o.id')
            ->selectRaw("{$day} as day, COUNT(*) as orders, SUM(".ProfitFigures::netGoodsSql().') as goods, COALESCE(SUM(c.cogs), 0) as cogs')
            ->groupBy('day')->get()->keyBy('day');

        $series = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $a = $placed->get($key);
            $b = $recognized->get($key);
            $goods = (float) ($b->goods ?? 0);
            $cogs = (float) ($b->cogs ?? 0);
            $series[] = [
                'day' => $key, 'orders' => (int) ($a->orders ?? 0), 'revenue' => (float) ($a->revenue ?? 0),
                'completed_orders' => (int) ($b->orders ?? 0), 'net_revenue' => $goods, 'cogs' => $cogs, 'gross_profit' => round($goods - $cogs, 2),
            ];
        }

        return $series;
    }

    /**
     * Each marketplace shop and own sales channel side by side, on the same basis as the totals.
     *
     * @return list<array<string, mixed>>
     */
    private function byShop(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom, CarbonImmutable $prevTo): array
    {
        $key = fn (Builder $q) => $q->groupBy('o.marketplace_account_id', 'o.sales_channel_id')->get()
            ->keyBy(fn ($r) => $r->marketplace_account_id ? 'a'.$r->marketplace_account_id : ($r->sales_channel_id ? 'c'.$r->sales_channel_id : 'x'));
        $base = fn () => DB::table('orders as o')->where('o.tenant_id', $this->tenant())->select('o.marketplace_account_id', 'o.sales_channel_id');

        $placed = $key($base()->whereBetween('o.placed_at', [$from, $to])
            ->selectRaw('SUM(CASE WHEN o.status <> ? THEN 1 ELSE 0 END) as ordered, SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN o.status <> ? THEN o.total ELSE 0 END) as revenue',
                [OrderStatus::CANCELLED, OrderStatus::CANCELLED, OrderStatus::CANCELLED]));
        $before = $key(RevenuePolicy::recognized($base(), 'o', $prevFrom, $prevTo)->selectRaw('SUM('.ProfitFigures::netGoodsSql().') as goods'));
        $recognised = $key(RevenuePolicy::recognized($base(), 'o', $from, $to)->selectRaw('COUNT(*) as n'));

        $accounts = MarketplaceAccount::query()->get(['id', 'ulid', 'shop_name', 'marketplace'])->keyBy(fn ($a) => 'a'.$a->id);
        $channels = SalesChannel::query()->get(['id', 'ulid', 'name'])->keyBy(fn ($c) => 'c'.$c->id);

        $rows = [];
        foreach ($placed->keys()->merge($recognised->keys())->unique() as $id) {
            if ($id === 'x') {
                continue;
            }
            $shop = $accounts->get($id) ?? $channels->get($id);
            if (! $shop) {
                continue;
            }
            $isAccount = str_starts_with($id, 'a');
            $p = $this->profit->period($from, $to, [$isAccount ? 'account' : 'channel' => $shop->id]);
            $previous = (float) ($before->get($id)->goods ?? 0);
            $rows[] = [
                'id' => $shop->ulid,
                'kind' => $isAccount ? 'account' : 'channel',
                'shop' => $isAccount ? $shop->shop_name : $shop->name,
                'marketplace' => $isAccount ? $shop->marketplace : 'manual',
                'ordered_orders' => (int) ($placed->get($id)->ordered ?? 0),
                'cancelled_orders' => (int) ($placed->get($id)->cancelled ?? 0),
                'revenue' => (float) ($placed->get($id)->revenue ?? 0),
                'orders' => $p['orders'],
                'returns' => $p['returns'],
                'net_revenue' => $p['revenue'],
                'cogs' => $p['cogs'],
                'gross_profit' => $p['gross_profit'],
                'fees' => $p['marketplace_fees'],
                'fees_unknown_orders' => $p['fees_unknown_orders'],
                'shipping' => $p['shipping'],
                // Contribution before selling expenses (which belong to the whole business).
                'profit' => $p['contribution_profit'],
                'margin' => $p['revenue'] > 0 ? round($p['contribution_profit'] / $p['revenue'] * 100, 1) : null,
                'average_order' => $p['orders'] > 0 ? round(($p['goods'] - $p['discount'] - $p['sales_tax']) / $p['orders'], 0) : null,
                'growth' => $previous > 0 ? round(($p['goods'] - $p['discount'] - $p['sales_tax'] - $previous) / $previous * 100, 1) : null,
            ];
        }
        usort($rows, fn ($a, $b) => $b['net_revenue'] <=> $a['net_revenue'] ?: $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    /** Shops and own channels the report can be scoped to. @return list<array<string, string>> */
    private function shops(): array
    {
        return MarketplaceAccount::query()->orderBy('shop_name')->get(['ulid', 'shop_name', 'marketplace'])
            ->map(fn ($a) => ['id' => $a->ulid, 'kind' => 'account', 'name' => $a->shop_name, 'marketplace' => $a->marketplace])
            ->concat(SalesChannel::query()->orderBy('name')->get(['ulid', 'name'])
                ->map(fn ($c) => ['id' => $c->ulid, 'kind' => 'channel', 'name' => $c->name, 'marketplace' => 'manual']))
            ->values()->all();
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
