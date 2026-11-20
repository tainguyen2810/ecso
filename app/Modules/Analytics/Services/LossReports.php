<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\ProfitFigures;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Đợt 14 "Cảnh báo đơn lỗ": orders that bring in less than they cost once the marketplace has taken its fees, and the
 * SKUs that lose money over a period ("SP nên tăng giá").
 *
 * Unlike the profit report (which waits until the marketplace completes an order), this looks at orders as soon as
 * they are placed, so the seller hears about a loss while it can still be fixed:
 *   lãi sau phí = goods after discounts and VAT − cost of goods − marketplace fee
 * Cost of goods is the cost captured at shipping, else the SKU's current cost; the fee is the settlement's when the
 * marketplace has settled the order, else the estimate from the shop's fee table (none for own sales channels).
 * Lines without any cost are left out of the cost (and the order is marked), so a missing cost never makes a loss.
 */
class LossReports
{
    /** Orders that are not (or no longer) a sale. */
    private const EXCLUDED = [OrderStatus::CANCELLED, OrderStatus::RETURNED];

    public function __construct(private readonly TenantContext $context) {}

    /** Orders placed in the range with their goods, cost and fee (alias x). */
    public function base(CarbonInterface $from, CarbonInterface $to): Builder
    {
        $costs = DB::table('order_items as i')->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->where('i.tenant_id', $this->tenant())->groupBy('i.order_id')
            ->selectRaw('i.order_id, SUM(COALESCE(i.cost_snapshot, NULLIF(v.cost_price, 0), 0) * i.quantity) as cogs, '
                .'SUM(CASE WHEN COALESCE(i.cost_snapshot, NULLIF(v.cost_price, 0)) IS NULL AND i.mapping_status <> \'service\' THEN 1 ELSE 0 END) as missing, '
                .'SUM(i.quantity) as units');

        return ProfitFigures::withFees(DB::table('orders as o')->where('o.tenant_id', $this->tenant())
            ->whereNotIn('o.status', self::EXCLUDED)->whereBetween('o.placed_at', [$from, $to]))
            ->leftJoinSub($costs, 'c', 'c.order_id', '=', 'o.id')
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'o.marketplace_account_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'o.sales_channel_id')
            ->selectRaw('o.id, o.ulid, o.external_order_id, o.placed_at, o.marketplace, o.loss_alerted_at, '
                ."COALESCE(ma.shop_name, sc.name, 'Khác') as channel, "
                .ProfitFigures::netGoodsSql().' as net, COALESCE(c.cogs, 0) as cogs, COALESCE(c.missing, 0) as missing, COALESCE(c.units, 0) as units, '
                .'COALESCE('.ProfitFigures::confirmedFeeSql().', '.ProfitFigures::estimatedFeeSql().', 0) as fee, '
                .'CASE WHEN s.id IS NOT NULL THEN \'confirmed\' WHEN '.ProfitFigures::estimatedFeeSql().' IS NOT NULL THEN \'estimated\' WHEN '.ProfitFigures::unknownFeeSql().' = 1 THEN \'unknown\' ELSE \'none\' END as fee_kind');
    }

    /** SQL over base(): an order's profit after fees. */
    private const PROFIT = '(x.net - x.cogs - x.fee)';

    /**
     * Loss orders of the range, biggest loss first.
     *
     * @return array<string, mixed>
     */
    public function orders(CarbonInterface $from, CarbonInterface $to): array
    {
        $loss = DB::query()->fromSub($this->base($from, $to), 'x')->where('x.cogs', '>', 0)->whereRaw(self::PROFIT.' < -0.5');
        $all = (clone $loss)->selectRaw('COUNT(*) as n, COALESCE(SUM(x.net), 0) as net, COALESCE(SUM(x.cogs), 0) as cogs, COALESCE(SUM(x.fee), 0) as fee, COALESCE(SUM('.self::PROFIT.'), 0) as profit')->first();
        $rows = (clone $loss)->orderByRaw(self::PROFIT)->limit(self::limit())->get();
        $skus = $this->skus($rows->pluck('id')->all());

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->ulid,
                'number' => $r->external_order_id,
                'placed_at' => CarbonImmutable::parse($r->placed_at)->toIso8601String(),
                'channel' => $r->channel,
                'net' => round((float) $r->net, 2),
                'cogs' => round((float) $r->cogs, 2),
                'fees' => round((float) $r->fee, 2),
                'fee_kind' => $r->fee_kind,
                'profit' => round((float) $r->net - (float) $r->cogs - (float) $r->fee, 2) + 0.0,
                'missing_cost_lines' => (int) $r->missing,
                'skus' => $skus[$r->id] ?? [],
            ])->all(),
            'totals' => [
                'orders' => (int) $all->n,
                'net' => round((float) $all->net, 2),
                'cogs' => round((float) $all->cogs, 2),
                'fees' => round((float) $all->fee, 2),
                'profit' => round((float) $all->profit, 2) + 0.0,
            ],
            'truncated' => $rows->count() < (int) $all->n,
            'total_rows' => (int) $all->n,
            'limit' => self::limit(),
        ];
    }

    /**
     * SKUs that lost money over the range once fees are taken, with the price that would break even.
     *
     * Break-even price: the loss per unit added back, grossed up for the fee share that grows with the price
     * (fee / goods of the SKU), rounded up to 1 000 đ.
     *
     * @return array<string, mixed>
     */
    public function products(CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = DB::query()->fromSub($this->base($from, $to), 'x')->select('x.id', 'x.fee', 'x.fee_kind');
        $lineTotals = DB::table('order_items')->where('tenant_id', $this->tenant())->groupBy('order_id')
            ->selectRaw('order_id, SUM(quantity * unit_price - discount) as lines_value');
        $share = '(i.quantity * i.unit_price - i.discount) / NULLIF(lt.lines_value, 0)';
        $grouped = DB::table('order_items as i')
            ->joinSub($orders, 'f', 'f.id', '=', 'i.order_id')
            ->leftJoinSub($lineTotals, 'lt', 'lt.order_id', '=', 'i.order_id')
            ->join('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->where('i.tenant_id', $this->tenant())
            ->whereRaw('COALESCE(i.cost_snapshot, NULLIF(v.cost_price, 0)) IS NOT NULL')
            ->groupBy('i.product_variant_id', 'v.ulid', 'v.sku', 'v.name', 'p.name', 'v.ref_price')
            ->selectRaw('v.ulid, v.sku, v.name as variant_name, p.name as product_name, v.ref_price, COUNT(DISTINCT i.order_id) as orders, SUM(i.quantity) as units, '
                .'SUM(i.quantity * i.unit_price - i.discount) as net, SUM(COALESCE(i.cost_snapshot, v.cost_price) * i.quantity) as cogs, '
                ."COALESCE(SUM(f.fee * COALESCE({$share}, 0)), 0) as fees, MAX(CASE WHEN f.fee_kind = 'unknown' THEN 1 ELSE 0 END) as fees_unknown");
        $loss = DB::query()->fromSub($grouped, 'g')->whereRaw('(g.net - g.cogs - g.fees) < -0.5');
        $all = (clone $loss)->selectRaw('COUNT(*) as n, COALESCE(SUM(g.net - g.cogs - g.fees), 0) as profit, COALESCE(SUM(g.units), 0) as units')->first();

        $rows = (clone $loss)->orderByRaw('(g.net - g.cogs - g.fees)')->limit(self::limit())->get()->map(function ($r) {
            $units = max(1, (int) $r->units);
            $net = (float) $r->net;
            $profit = $net - (float) $r->cogs - (float) $r->fees;
            $feeRate = $net > 0 ? min(0.6, (float) $r->fees / $net) : 0.0;
            $price = $net / $units;
            $breakEven = ceil(($price + (-$profit / $units) / (1 - $feeRate)) / 1000) * 1000;

            return [
                'variant' => ['id' => $r->ulid, 'sku' => $r->sku, 'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : ''))],
                'orders' => (int) $r->orders,
                'units' => (int) $r->units,
                'avg_price' => round($price, 2),
                'avg_cost' => round((float) $r->cogs / $units, 2),
                'fees' => round((float) $r->fees, 2),
                'fee_pct' => $net > 0 ? round((float) $r->fees / $net * 100, 1) : null,
                'fees_unknown' => (bool) $r->fees_unknown,
                'profit' => round($profit, 2) + 0.0,
                'ref_price' => $r->ref_price !== null ? round((float) $r->ref_price, 2) : null,
                'break_even' => (float) $breakEven,
            ];
        })->all();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows' => $rows,
            'totals' => ['products' => (int) $all->n, 'units' => (int) $all->units, 'profit' => round((float) $all->profit, 2) + 0.0],
            'truncated' => count($rows) < (int) $all->n,
            'total_rows' => (int) $all->n,
            'limit' => self::limit(),
        ];
    }

    /**
     * Loss orders placed since $since that nobody was told about yet (for the alert), oldest first.
     *
     * @return list<object>
     */
    public function unalerted(CarbonInterface $since, int $limit = 200): array
    {
        return DB::query()->fromSub($this->base($since, now()), 'x')->whereNull('x.loss_alerted_at')
            ->where('x.cogs', '>', 0)->whereRaw(self::PROFIT.' < -0.5')
            ->selectRaw('x.*, '.self::PROFIT.' as profit')->orderBy('x.placed_at')->limit($limit)->get()->all();
    }

    /**
     * Seller SKUs of each order (at most 3).
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, list<string>>
     */
    private function skus(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('order_items as i')->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->whereIn('i.order_id', $orderIds)->get(['i.order_id', DB::raw('COALESCE(v.sku, i.seller_sku) as sku')])
            ->groupBy('order_id')->map(fn ($g) => $g->pluck('sku')->filter()->unique()->take(3)->values()->all())->all();
    }

    private static function limit(): int
    {
        return (int) config('ecso.reports.row_limit', 2000);
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
