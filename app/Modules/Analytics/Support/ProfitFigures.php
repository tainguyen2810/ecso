<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Analytics\Services\BusinessReports;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one place profit is computed, for the finance page and the profit and loss report alike.
 *
 *   Net revenue          = goods of orders recognised in the period (RevenuePolicy) − discounts − approved returns
 *   Cost of goods        = cost captured at shipping − cost (as shipped) of returned goods put back on the shelf
 *   Gross profit         = net revenue − cost of goods
 *   Contribution profit  = gross profit − marketplace fees (confirmed + estimated) + shipping charged to buyers
 *                          − selling expenses (payment vouchers of a "sales" type)
 *   Management profit    = contribution − stock losses − operating and other expenses + other income  (estimate,
 *                          not accounting profit)
 *
 * Marketplace fees of an order are CONFIRMED when its settlement (đối soát) is imported, ESTIMATED from the shop's fee
 * table when not, and UNKNOWN when the shop has no fee table: unknown fees are counted, never shown as 0. Orders of
 * the shop's own channels and website carry no marketplace fee.
 *
 * A scope (one marketplace shop or one own sales channel) keeps only that shop's orders; stock losses and vouchers
 * belong to the whole business, so they are null in a scoped view and so is management profit.
 */
final class ProfitFigures
{
    public function __construct(private readonly TenantContext $context) {}

    /** Recognised orders of the period (alias o), with the scope applied. */
    public function recognized(CarbonInterface $from, CarbonInterface $to, ?array $scope = null): Builder
    {
        return $this->scoped(RevenuePolicy::recognized(DB::table('orders as o')->where('o.tenant_id', $this->tenant()), 'o', $from, $to), $scope);
    }

    /** @param  array{account?: int, channel?: int}|null  $scope */
    public function scoped(Builder $query, ?array $scope, string $orders = 'o'): Builder
    {
        return $query
            ->when($scope['account'] ?? null, fn ($q, $id) => $q->where("{$orders}.marketplace_account_id", $id))
            ->when($scope['channel'] ?? null, fn ($q, $id) => $q->where("{$orders}.sales_channel_id", $id));
    }

    /** Joins each order (alias o) to its settlement (s) and to its shop's fee table (cr). */
    public static function withFees(Builder $query): Builder
    {
        return $query
            ->leftJoin('marketplace_settlements as s', fn ($j) => $j->on('s.marketplace_account_id', '=', 'o.marketplace_account_id')->on('s.external_order_id', '=', 'o.external_order_id'))
            // One shop-level rule per shop (the latest, as MarketplacePricing picks it), so an order is never counted twice.
            ->leftJoin('channel_price_rules as cr', fn ($j) => $j->on('cr.marketplace_account_id', '=', 'o.marketplace_account_id')->whereNull('cr.category_id')
                ->whereRaw('cr.id = (SELECT MAX(cr2.id) FROM channel_price_rules cr2 WHERE cr2.marketplace_account_id = o.marketplace_account_id AND cr2.category_id IS NULL)'));
    }

    /** SQL: fees the marketplace charged on an order's settlement (NULL without one). */
    public static function confirmedFeeSql(): string
    {
        return 'CASE WHEN s.id IS NOT NULL THEN s.commission_fee + s.payment_fee + s.service_fee + s.fixed_fee + s.shipping_fee END';
    }

    /** SQL: fees estimated from the shop's fee table for a marketplace order without settlement (NULL otherwise). */
    public static function estimatedFeeSql(): string
    {
        return 'CASE WHEN s.id IS NULL AND '.self::isMarketplaceSql().' AND cr.id IS NOT NULL '
            .'THEN (o.subtotal - o.discount) * (COALESCE(cr.commission_pct, 0) + COALESCE(cr.payment_pct, 0) + COALESCE(cr.service_pct, 0)) / 100 + COALESCE(cr.fixed_fee, 0) END';
    }

    /** SQL: 1 for a marketplace order with neither settlement nor fee table. */
    public static function unknownFeeSql(): string
    {
        return 'CASE WHEN s.id IS NULL AND '.self::isMarketplaceSql().' AND cr.id IS NULL THEN 1 ELSE 0 END';
    }

    public static function isMarketplaceSql(string $orders = 'o'): string
    {
        return "{$orders}.marketplace NOT IN ('".implode("','", RevenuePolicy::AT_DELIVERY)."')";
    }

    /** Approved returns of recognised orders (alias r, order ro, lines rv), with the scope applied. */
    public function returns(?array $scope = null): Builder
    {
        return $this->scoped(RevenuePolicy::recognized(DB::table('order_returns as r')->join('orders as ro', 'ro.id', '=', 'r.order_id'), 'ro'), $scope, 'ro')
            ->leftJoinSub(ReturnValues::lines(), 'rv', 'rv.order_return_id', '=', 'r.id')
            ->where('r.tenant_id', $this->tenant())->whereIn('r.status', ReturnValues::COUNTED);
    }

    /**
     * Every figure of the period.
     *
     * @param  array{account?: int, channel?: int}|null  $scope
     * @return array<string, float|int|null>
     */
    public function period(CarbonInterface $from, CarbonInterface $to, ?array $scope = null): array
    {
        $orders = $this->recognized($from, $to, $scope)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(o.subtotal), 0) as goods, COALESCE(SUM(o.discount), 0) as discount, COALESCE(SUM(o.shipping_fee), 0) as shipping')->first();
        $cost = $this->recognized($from, $to, $scope)->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->selectRaw('COALESCE(SUM(i.cost_snapshot * i.quantity), 0) as cogs, COALESCE(SUM(CASE WHEN i.cost_snapshot IS NULL AND i.mapping_status <> \'service\' THEN 1 ELSE 0 END), 0) as missing')
            ->first();
        $fees = self::withFees($this->recognized($from, $to, $scope))
            ->selectRaw('COALESCE(SUM('.self::confirmedFeeSql().'), 0) as confirmed, COUNT(s.id) as confirmed_orders, '
                .'COALESCE(SUM('.self::estimatedFeeSql().'), 0) as estimated, COUNT('.self::estimatedFeeSql().') as estimated_orders, '
                .'COALESCE(SUM('.self::unknownFeeSql().'), 0) as unknown_orders')
            ->first();
        $returns = (float) $this->returns($scope)->whereBetween('r.created_at', [$from, $to])->selectRaw('COALESCE(SUM('.ReturnValues::valueSql().'), 0) as v')->value('v');
        $cogsBack = (float) $this->returns($scope)->whereBetween('r.inspected_at', [$from, $to])->selectRaw('COALESCE(SUM(rv.cost_back), 0) as v')->value('v');

        $p = [
            'orders' => (int) $orders->n,
            'goods' => (float) $orders->goods,
            'discount' => (float) $orders->discount,
            'returns' => $returns,
            'shipping' => (float) $orders->shipping,
            'cogs_out' => (float) $cost->cogs,
            'cogs_back' => $cogsBack,
            'fees_confirmed' => (float) $fees->confirmed,
            'fees_estimated' => (float) $fees->estimated,
        ];
        $p['revenue'] = $p['goods'] - $p['discount'] - $p['returns'];
        $p['cogs'] = $p['cogs_out'] - $p['cogs_back'];
        $p['gross_profit'] = $p['revenue'] - $p['cogs'];
        $p['marketplace_fees'] = $p['fees_confirmed'] + $p['fees_estimated'];

        $business = $scope ? null : $this->businessWide($from, $to);
        $p['selling_expenses'] = $business['selling_expenses'] ?? null;
        $p['contribution_profit'] = $p['gross_profit'] - $p['marketplace_fees'] + $p['shipping'] - ($p['selling_expenses'] ?? 0);
        foreach (['write_off', 'stocktake', 'stock_loss', 'operating_expenses', 'misc_expenses', 'other_expenses', 'other_income'] as $key) {
            $p[$key] = $business[$key] ?? null;
        }
        $p['profit'] = $business
            ? $p['contribution_profit'] - $p['stock_loss'] - $p['operating_expenses'] - $p['misc_expenses'] + $p['other_income']
            : null;

        // + 0.0 turns a negated zero (-0) into 0 so it never shows as "-0 đ".
        $rounded = array_map(fn ($v) => is_float($v) ? round($v, 2) + 0.0 : $v, $p);

        return $rounded + [
            'missing_cost_lines' => (int) $cost->missing,
            'fees_confirmed_orders' => (int) $fees->confirmed_orders,
            'fees_estimated_orders' => (int) $fees->estimated_orders,
            'fees_unknown_orders' => (int) $fees->unknown_orders,
        ];
    }

    /**
     * Stock losses and the vouchers that count in profit and loss, by profit group of their type.
     *
     * @return array<string, float>
     */
    private function businessWide(CarbonInterface $from, CarbonInterface $to): array
    {
        $value = fn (array $types, ?string $extra = null) => (float) DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->where('m.tenant_id', $this->tenant())->whereBetween('m.created_at', [$from, $to])->whereIn('m.type', $types)
            ->when($extra, fn ($q) => $q->whereRaw($extra))
            ->selectRaw('COALESCE(SUM(m.on_hand_delta * COALESCE(m.unit_cost, v.cost_price)), 0) as v')->value('v');
        $writeOffReasons = "m.reason IN ('".implode("','", BusinessReports::WRITE_OFF_REASONS)."')";
        $writeOff = -$value(BusinessReports::STOCK_COLUMNS['damage_out']) - $value(['ADJUSTMENT_OUT'], $writeOffReasons);
        $stocktake = -$value(['ADJUSTMENT_OUT'], "(m.reason IS NULL OR NOT {$writeOffReasons})") - $value(BusinessReports::STOCK_COLUMNS['adjust_in']);

        $vouchers = DB::table('cash_vouchers as cv')->leftJoin('cash_voucher_types as t', 't.id', '=', 'cv.cash_voucher_type_id')
            ->where('cv.tenant_id', $this->tenant())->whereNull('cv.voided_at')->where('cv.counts_in_pnl', true)->whereBetween('cv.paid_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN cv.direction = 'receipt' THEN cv.amount ELSE 0 END), 0) as income, "
                ."COALESCE(SUM(CASE WHEN cv.direction = 'payment' AND t.pnl_group = 'sales' THEN cv.amount ELSE 0 END), 0) as selling, "
                ."COALESCE(SUM(CASE WHEN cv.direction = 'payment' AND t.pnl_group = 'operating' THEN cv.amount ELSE 0 END), 0) as operating, "
                ."COALESCE(SUM(CASE WHEN cv.direction = 'payment' THEN cv.amount ELSE 0 END), 0) as expenses")
            ->first();

        return [
            'write_off' => $writeOff,
            'stocktake' => $stocktake,
            'stock_loss' => $writeOff + $stocktake,
            'selling_expenses' => (float) $vouchers->selling,
            'operating_expenses' => (float) $vouchers->operating,
            'misc_expenses' => (float) $vouchers->expenses - (float) $vouchers->selling - (float) $vouchers->operating,
            'other_expenses' => (float) $vouchers->expenses,
            'other_income' => (float) $vouchers->income,
        ];
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
