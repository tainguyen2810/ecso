<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\ProfitFigures;
use App\Modules\Analytics\Support\ReturnValues;
use App\Modules\Analytics\Support\RevenuePolicy;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The orders and returns behind each figure of the finance page, on exactly the same filters, so that the rows of a
 * drill-down add up to the figure on its card.
 */
class FinanceDetails
{
    public const METRICS = ['revenue', 'awaiting', 'cancelled', 'returns', 'fees', 'payouts'];

    public const LIMIT = 2000;

    public function __construct(private readonly ProfitFigures $profit, private readonly TenantContext $context) {}

    /**
     * @param  array{account?: int, channel?: int}|null  $scope
     * @return array{metric: string, rows: list<array<string, mixed>>, total: array<string, float|int>, truncated: bool}
     */
    public function build(string $metric, CarbonImmutable $from, CarbonImmutable $to, ?array $scope, bool $withCost): array
    {
        $query = match ($metric) {
            'revenue' => $this->orders($this->profit->recognized($from, $to, $scope)),
            'fees' => $this->orders($this->profit->recognized($from, $to, $scope)->whereRaw(ProfitFigures::isMarketplaceSql())),
            'payouts' => $this->orders($this->profit->recognized($from, $to, $scope)->whereRaw(ProfitFigures::isMarketplaceSql())->whereNotNull('s.id')),
            'awaiting' => $this->orders($this->profit->scoped(RevenuePolicy::awaitingCompletion($this->base(), 'o', $from, $to), $scope)),
            'cancelled' => $this->orders($this->profit->scoped($this->base()->whereBetween('o.placed_at', [$from, $to])->where('o.status', OrderStatus::CANCELLED), $scope)),
            'returns' => $this->returns($from, $to, $scope),
        };

        $rows = $query->limit(self::LIMIT + 1)->get();
        $truncated = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT)->map(function ($r) use ($withCost) {
            $row = (array) $r;
            foreach (['goods', 'discount', 'shipping', 'total', 'cogs', 'fee', 'payout', 'value', 'cost_back', 'gross_profit'] as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $row[$key] === null ? null : round((float) $row[$key], 2);
                }
            }
            if (! $withCost) {
                unset($row['cogs'], $row['cost_back'], $row['gross_profit']);
            }

            return $row;
        })->values();

        $sum = fn (string $key) => round((float) $rows->sum($key), 2);
        $total = ['count' => $rows->count()] + match ($metric) {
            'returns' => ['value' => $sum('value')] + ($withCost ? ['cost_back' => $sum('cost_back')] : []),
            'payouts' => ['payout' => $sum('payout'), 'fee' => $sum('fee')],
            'fees' => ['fee' => $sum('fee'), 'unknown' => $rows->where('fee_status', 'unknown')->count()],
            default => ['goods' => $sum('goods'), 'shipping' => $sum('shipping'), 'total' => $sum('total')] + ($withCost ? ['cogs' => $sum('cogs')] : []),
        };

        return ['metric' => $metric, 'rows' => $rows->all(), 'total' => $total, 'truncated' => $truncated];
    }

    private function base(): Builder
    {
        return DB::table('orders as o')->where('o.tenant_id', $this->context->id());
    }

    /** One row per order: goods after discounts, shipping, cost captured at shipping, fee and its status. */
    private function orders(Builder $query): Builder
    {
        $cost = DB::table('order_items')->selectRaw('order_id, SUM(cost_snapshot * quantity) as cogs')->groupBy('order_id');

        return ProfitFigures::withFees($query)
            ->leftJoinSub($cost, 'c', 'c.order_id', '=', 'o.id')
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'o.marketplace_account_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'o.sales_channel_id')
            ->selectRaw("o.ulid as id, o.external_order_id as code, o.marketplace, o.status, COALESCE(ma.shop_name, sc.name, 'Khác') as shop, "
                .'o.placed_at, '.RevenuePolicy::dateSql('o').' as recognized_at, o.subtotal - o.discount as goods, o.discount, o.shipping_fee as shipping, o.total, '
                .'c.cogs, o.subtotal - o.discount - COALESCE(c.cogs, 0) as gross_profit, '
                .'COALESCE('.ProfitFigures::confirmedFeeSql().', '.ProfitFigures::estimatedFeeSql().') as fee, '
                ."CASE WHEN s.id IS NOT NULL THEN 'confirmed' WHEN ".ProfitFigures::unknownFeeSql()." = 1 THEN 'unknown' WHEN cr.id IS NOT NULL AND ".ProfitFigures::isMarketplaceSql()." THEN 'estimated' ELSE 'none' END as fee_status, "
                .'s.net_amount as payout, s.settled_at')
            ->orderBy('o.placed_at')->orderBy('o.id');
    }

    private function returns(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): Builder
    {
        return $this->profit->returns($scope)
            ->where(fn ($q) => $q->whereBetween('r.created_at', [$from, $to])->orWhereBetween('r.inspected_at', [$from, $to]))
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'ro.marketplace_account_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'ro.sales_channel_id')
            ->selectRaw("r.ulid as id, r.number as code, ro.external_order_id as order_code, ro.ulid as order_id, r.status, COALESCE(ma.shop_name, sc.name, 'Khác') as shop, r.created_at, r.inspected_at, "
                // A return counts in the period it was approved (value) and in the period its goods went back on the shelf (cost).
                .'CASE WHEN r.created_at BETWEEN ? AND ? THEN '.ReturnValues::valueSql().' ELSE 0 END as value, '
                .'CASE WHEN r.inspected_at BETWEEN ? AND ? THEN COALESCE(rv.cost_back, 0) ELSE 0 END as cost_back', [$from, $to, $from, $to])
            ->orderBy('r.created_at')->orderBy('r.id');
    }
}
