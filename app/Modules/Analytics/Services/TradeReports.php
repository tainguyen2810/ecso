<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Detail reports on the trading side: sales by product, returns, customer payments, goods purchased and customers.
 * Read-only; every query is scoped to the current tenant explicitly because it runs on the query builder.
 */
class TradeReports
{
    private const EXCLUDED = [OrderStatus::CANCELLED, OrderStatus::RETURNED];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Sales per SKU for orders placed in the range (cancelled and returned orders excluded). Line discounts are
     * per SKU; order-level discounts are not spread over lines, so they show only in the sales report totals.
     *
     * @return array<string, mixed>
     */
    public function salesByProduct(CarbonImmutable $from, CarbonImmutable $to, ?string $q = null): array
    {
        $rows = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->where('o.tenant_id', $this->tenant())
            ->whereBetween('o.placed_at', [$from, $to])
            ->whereNotIn('o.status', self::EXCLUDED)
            ->when($q, fn ($w) => $w->where(fn ($s) => $s->where('v.sku', 'like', "%{$q}%")->orWhere('p.name', 'like', "%{$q}%")->orWhere('i.name', 'like', "%{$q}%")))
            ->groupBy('i.product_variant_id', 'v.ulid', 'v.sku', 'v.name', 'p.name')
            ->selectRaw('v.ulid, v.sku, v.name as variant_name, p.name as product_name, MAX(i.name) as item_name, MAX(i.seller_sku) as seller_sku, '
                .'COUNT(DISTINCT o.id) as orders, SUM(i.quantity) as units, SUM(i.quantity * i.unit_price) as goods, SUM(i.discount) as discount, '
                .'SUM(COALESCE(i.cost_snapshot, 0) * i.quantity) as cogs, SUM(CASE WHEN i.cost_snapshot IS NULL THEN 1 ELSE 0 END) as missing')
            ->orderByDesc('goods')
            ->limit(2000)
            ->get();

        $out = $rows->map(function ($r) {
            $net = (float) $r->goods - (float) $r->discount;
            $cogs = (float) $r->cogs;

            return [
                'variant' => $r->ulid
                    ? ['id' => $r->ulid, 'sku' => $r->sku, 'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : ''))]
                    : ['id' => null, 'sku' => $r->seller_sku, 'name' => $r->item_name.' (chưa ghép SKU)'],
                'orders' => (int) $r->orders,
                'units' => (int) $r->units,
                'goods' => round((float) $r->goods, 2),
                'discount' => round((float) $r->discount, 2),
                'net' => round($net, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($net - $cogs, 2),
                'margin' => $net > 0 ? round(($net - $cogs) / $net * 100, 1) : null,
                'missing_cost_lines' => (int) $r->missing,
            ];
        })->all();

        return ['range' => $this->range($from, $to), 'rows' => $out, 'totals' => $this->sum($out, ['orders', 'units', 'goods', 'discount', 'net', 'cogs', 'gross_profit', 'missing_cost_lines'])];
    }

    /**
     * Returns opened in the range (rejected ones left out), one row per return or per SKU.
     *
     * @return array<string, mixed>
     */
    public function returns(CarbonImmutable $from, CarbonImmutable $to, string $by = 'order'): array
    {
        $base = fn () => DB::table('order_returns as r')
            ->where('r.tenant_id', $this->tenant())
            ->where('r.status', '!=', OrderReturn::REJECTED)
            ->whereBetween('r.created_at', [$from, $to]);

        if ($by === 'product') {
            $rows = $base()->join('order_return_lines as l', 'l.order_return_id', '=', 'r.id')
                ->join('order_items as i', 'i.id', '=', 'l.order_item_id')
                ->leftJoin('product_variants as v', 'v.id', '=', 'l.product_variant_id')
                ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
                ->groupBy('l.product_variant_id', 'v.ulid', 'v.sku', 'v.name', 'p.name')
                ->selectRaw('v.ulid, v.sku, v.name as variant_name, p.name as product_name, MAX(i.name) as item_name, COUNT(DISTINCT r.id) as returns, '
                    .'SUM(l.quantity) as quantity, SUM(l.received_qty) as received, SUM(l.sellable_qty) as sellable, SUM(l.damaged_qty) as damaged, '
                    .'SUM(l.quantity * i.unit_price) as value')
                ->orderByDesc('quantity')->limit(2000)->get()
                ->map(fn ($r) => [
                    'variant' => ['id' => $r->ulid, 'sku' => $r->sku, 'name' => $r->ulid ? trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : '')) : $r->item_name],
                    'returns' => (int) $r->returns,
                    'quantity' => (int) $r->quantity,
                    'received' => (int) $r->received,
                    'sellable' => (int) $r->sellable,
                    'damaged' => (int) $r->damaged,
                    'value' => round((float) $r->value, 2),
                ])->all();

            return ['range' => $this->range($from, $to), 'by' => $by, 'rows' => $rows, 'totals' => $this->sum($rows, ['returns', 'quantity', 'received', 'sellable', 'damaged', 'value'])];
        }

        $qty = DB::table('order_return_lines')->groupBy('order_return_id')->selectRaw('order_return_id, SUM(quantity) as quantity');
        $rows = $base()->join('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoinSub($qty, 'q', 'q.order_return_id', '=', 'r.id')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->orderByDesc('r.created_at')->limit(2000)
            ->get(['r.ulid', 'r.number', 'r.type', 'r.status', 'r.reason', 'r.refund_amount', 'r.created_at', 'o.ulid as order_id', 'o.external_order_id', 'o.buyer_name', 'c.name as customer', 'q.quantity'])
            ->map(fn ($r) => [
                'id' => $r->ulid,
                'number' => $r->number,
                'type' => $r->type,
                'status' => $r->status,
                'reason' => $r->reason,
                'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String(),
                'order' => ['id' => $r->order_id, 'number' => $r->external_order_id],
                'customer' => $r->customer ?? $r->buyer_name,
                'quantity' => (int) $r->quantity,
                'refund_amount' => round((float) $r->refund_amount, 2),
            ])->all();

        return ['range' => $this->range($from, $to), 'by' => $by, 'rows' => $rows, 'totals' => ['returns' => count($rows), ...$this->sum($rows, ['quantity', 'refund_amount'])]];
    }

    /**
     * Customer payments and refunds on own-channel orders (voided ones excluded) by method, staff or day.
     *
     * @return array<string, mixed>
     */
    public function payments(CarbonImmutable $from, CarbonImmutable $to, string $by = 'method'): array
    {
        $base = DB::table('order_payments as op')
            ->where('op.tenant_id', $this->tenant())
            ->whereNull('op.voided_at')
            ->whereBetween('op.paid_at', [$from, $to]);
        $metrics = "COUNT(*) as entries, SUM(CASE WHEN op.kind = 'refund' THEN 0 ELSE op.amount END) as received, SUM(CASE WHEN op.kind = 'refund' THEN op.amount ELSE 0 END) as refunded";

        $grouped = match ($by) {
            'staff' => (clone $base)->leftJoin('users as u', 'u.id', '=', 'op.created_by')
                ->groupBy('op.created_by', 'u.name')->selectRaw("COALESCE(u.name, 'Không rõ') as label, {$metrics}")->orderByDesc('received')->get(),
            'day' => (clone $base)->groupByRaw('DATE(op.paid_at)')->selectRaw("DATE(op.paid_at) as label, {$metrics}")->orderBy('label')->get(),
            default => (clone $base)->groupBy('op.method')->selectRaw("op.method as label, {$metrics}")->orderByDesc('received')->get(),
        };

        $rows = $grouped->map(fn ($r) => [
            'label' => (string) $r->label,
            'entries' => (int) $r->entries,
            'received' => round((float) $r->received, 2),
            'refunded' => round((float) $r->refunded, 2),
            'net' => round((float) $r->received - (float) $r->refunded, 2),
        ])->all();

        return ['range' => $this->range($from, $to), 'by' => $by, 'rows' => $rows, 'totals' => $this->sum($rows, ['entries', 'received', 'refunded', 'net'])];
    }

    /**
     * Goods received into stock in the range (opening balances left out), by supplier, SKU or day, with goods
     * returned to suppliers alongside. Receipts not tied to a purchase order are grouped as "Nhập lẻ".
     *
     * @return array<string, mixed>
     */
    public function purchases(CarbonImmutable $from, CarbonImmutable $to, string $by = 'supplier'): array
    {
        $base = DB::table('stock_receipt_lines as l')
            ->join('stock_receipts as r', 'r.id', '=', 'l.stock_receipt_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')
            ->where('r.tenant_id', $this->tenant())
            ->where('r.source', '!=', 'opening')
            ->whereBetween('r.created_at', [$from, $to]);
        $metrics = 'COUNT(DISTINCT r.id) as receipts, SUM(l.quantity) as quantity, SUM(l.quantity * l.unit_cost) as value';

        $returns = DB::table('supplier_returns as sr')->where('sr.tenant_id', $this->tenant())->whereBetween('sr.created_at', [$from, $to]);

        if ($by === 'product') {
            $rows = (clone $base)->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                ->groupBy('l.product_variant_id', 'v.ulid', 'v.sku', 'v.name', 'p.name')
                ->selectRaw("v.ulid as id, v.sku, TRIM(CONCAT(p.name, CASE WHEN v.name IS NULL OR v.name = '' THEN '' ELSE CONCAT(' - ', v.name) END)) as label, {$metrics}")
                ->orderByDesc('value')->limit(2000)->get();
            $returned = (clone $returns)->join('supplier_return_lines as rl', 'rl.supplier_return_id', '=', 'sr.id')
                ->groupBy('rl.product_variant_id')->selectRaw('rl.product_variant_id as k, SUM(rl.quantity * rl.unit_cost) as value')->pluck('value', 'k');
            $ids = DB::table('product_variants')->whereIn('ulid', $rows->pluck('id'))->pluck('id', 'ulid');
            $key = fn ($r) => $ids[$r->id] ?? null;
        } elseif ($by === 'day') {
            $rows = (clone $base)->groupByRaw('DATE(r.created_at)')->selectRaw("DATE(r.created_at) as label, NULL as id, NULL as sku, {$metrics}")->orderBy('label')->get();
            $returned = (clone $returns)->groupByRaw('DATE(sr.created_at)')->selectRaw('DATE(sr.created_at) as k, SUM(sr.total) as value')->pluck('value', 'k');
            $key = fn ($r) => $r->label;
        } else {
            $rows = (clone $base)->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
                ->groupBy('po.supplier_id', 's.ulid', 's.name')
                ->selectRaw("s.ulid as id, NULL as sku, COALESCE(s.name, 'Nhập lẻ (không qua đơn mua)') as label, {$metrics}")
                ->orderByDesc('value')->get();
            $returned = (clone $returns)->join('suppliers as s', 's.id', '=', 'sr.supplier_id')->groupBy('s.ulid')->selectRaw('s.ulid as k, SUM(sr.total) as value')->pluck('value', 'k');
            $key = fn ($r) => $r->id;
        }

        $out = $rows->map(function ($r) use ($returned, $key) {
            $back = (float) ($returned[$key($r)] ?? 0);

            return [
                'id' => $r->id,
                'sku' => $r->sku,
                'label' => (string) $r->label,
                'receipts' => (int) $r->receipts,
                'quantity' => (int) $r->quantity,
                'value' => round((float) $r->value, 2),
                'returned' => round($back, 2),
                'net' => round((float) $r->value - $back, 2),
            ];
        })->all();

        return ['range' => $this->range($from, $to), 'by' => $by, 'rows' => $out, 'totals' => $this->sum($out, ['receipts', 'quantity', 'value', 'returned', 'net'])];
    }

    /**
     * Buyers in the range. A customer is "new" when their first order ever falls in the range. Orders without a
     * customer record (walk-in sales, marketplace buyers) are counted apart because they cannot be told apart.
     *
     * @return array<string, mixed>
     */
    public function customers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $first = DB::table('orders')->where('tenant_id', $this->tenant())->whereNotNull('customer_id')->whereNotIn('status', self::EXCLUDED)
            ->groupBy('customer_id')->selectRaw('customer_id, MIN(placed_at) as first_at, MAX(placed_at) as last_at, COUNT(*) as lifetime_orders');
        $placed = fn (): Builder => DB::table('orders as o')->where('o.tenant_id', $this->tenant())->whereBetween('o.placed_at', [$from, $to])->whereNotIn('o.status', self::EXCLUDED);

        $rows = $placed()->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('customer_groups as g', 'g.id', '=', 'c.customer_group_id')
            ->joinSub($first, 'f', 'f.customer_id', '=', 'o.customer_id')
            ->groupBy('o.customer_id', 'c.ulid', 'c.code', 'c.name', 'g.name', 'f.first_at', 'f.last_at', 'f.lifetime_orders')
            ->selectRaw('c.ulid, c.code, c.name, g.name as group_name, f.first_at, f.last_at, f.lifetime_orders, COUNT(*) as orders, SUM(o.total) as revenue')
            ->orderByDesc('revenue')->limit(500)->get()
            ->map(fn ($r) => [
                'customer' => ['id' => $r->ulid, 'code' => $r->code, 'name' => $r->name, 'group' => $r->group_name],
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
                'average' => $r->orders ? round((float) $r->revenue / (int) $r->orders, 2) : 0,
                'lifetime_orders' => (int) $r->lifetime_orders,
                'first_order_at' => CarbonImmutable::parse($r->first_at)->toIso8601String(),
                'last_order_at' => CarbonImmutable::parse($r->last_at)->toIso8601String(),
                'is_new' => CarbonImmutable::parse($r->first_at)->gte($from),
            ])->all();

        $byDay = $placed()->leftJoinSub($first, 'f', 'f.customer_id', '=', 'o.customer_id')
            ->groupByRaw('DATE(o.placed_at)')
            ->selectRaw('DATE(o.placed_at) as day, COUNT(DISTINCT CASE WHEN o.customer_id IS NOT NULL AND f.first_at >= ? THEN o.customer_id END) as new_customers, '
                .'COUNT(DISTINCT CASE WHEN o.customer_id IS NOT NULL AND f.first_at < ? THEN o.customer_id END) as returning_customers, '
                .'SUM(CASE WHEN o.customer_id IS NULL THEN 1 ELSE 0 END) as anonymous_orders', [$from, $from])
            ->orderBy('day')->get()->keyBy('day');
        $days = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $r = $byDay->get($d->toDateString());
            $days[] = ['day' => $d->toDateString(), 'new' => (int) ($r->new_customers ?? 0), 'returning' => (int) ($r->returning_customers ?? 0), 'anonymous_orders' => (int) ($r->anonymous_orders ?? 0)];
        }

        $new = count(array_filter($rows, fn ($r) => $r['is_new']));

        return [
            'range' => $this->range($from, $to),
            'summary' => [
                'customers' => count($rows),
                'new' => $new,
                'returning' => count($rows) - $new,
                'revenue' => round(array_sum(array_column($rows, 'revenue')), 2),
                'anonymous_orders' => array_sum(array_column($days, 'anonymous_orders')),
            ],
            'rows' => $rows,
            'days' => $days,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<string, float|int>
     */
    private function sum(array $rows, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = array_sum(array_column($rows, $k));
            if (is_float($out[$k])) {
                $out[$k] = round($out[$k], 2);
            }
        }

        return $out;
    }

    /** @return array{from: string, to: string} */
    private function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
