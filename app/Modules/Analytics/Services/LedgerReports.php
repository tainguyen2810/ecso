<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Profit and loss with the previous period beside it, and what customers owe and what is owed to suppliers. */
class LedgerReports
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Profit and loss for the range and for the period of the same length just before it. Revenue is goods sold on
     * orders placed in the period (cancelled ones excluded) less returns opened in the period; cost of goods is the
     * ledger cost captured at shipping less the cost of goods put back on the shelf from returns. Marketplace fees
     * and other income and expenses are not in the system yet, so they are not here.
     *
     * @return array<string, mixed>
     */
    public function profitLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $prevTo = $from->subDay()->endOfDay();
        $prevFrom = $prevTo->subDays($days - 1)->startOfDay();

        $current = $this->period($from, $to);
        $previous = $this->period($prevFrom, $prevTo);

        $labels = [
            ['revenue', 'I. Doanh thu thuần (1 - 2 - 3 + 4)', 0, 'sales'],
            ['goods', '1. Tiền hàng bán ra', 1, 'sales'],
            ['discount', '2. Chiết khấu', 1, 'sales'],
            ['returns', '3. Hàng trả lại', 1, 'returns'],
            ['shipping', '4. Phí giao hàng thu của khách', 1, 'sales'],
            ['cogs', 'II. Giá vốn hàng bán (5 - 6)', 0, null],
            ['cogs_out', '5. Giá vốn hàng xuất bán', 1, 'products'],
            ['cogs_back', '6. Giá vốn hàng trả lại nhập kho', 1, 'returns'],
            ['gross_profit', 'III. Lợi nhuận gộp (I - II)', 0, null],
            ['stock_loss', 'IV. Hao hụt kho (7 + 8)', 0, null],
            ['write_off', '7. Hàng hủy, mất', 1, 'stock'],
            ['stocktake', '8. Chênh lệch kiểm kê, điều chỉnh', 1, 'stock'],
            ['profit', 'Lợi nhuận (III - IV)', 0, null],
        ];

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_range' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'lines' => array_map(fn ($l) => [
                'key' => $l[0],
                'label' => $l[1],
                'level' => $l[2],
                'report' => $l[3],
                'current' => $current[$l[0]],
                'previous' => $previous[$l[0]],
                'change' => $previous[$l[0]] != 0 ? round(($current[$l[0]] - $previous[$l[0]]) / abs($previous[$l[0]]) * 100, 1) : null,
            ], $labels),
            'missing_cost_lines' => $current['missing_cost_lines'],
        ];
    }

    /**
     * Own-channel orders not fully paid, per customer. Marketplace orders are left out: the marketplace collects.
     *
     * @return array<string, mixed>
     */
    public function receivables(): array
    {
        $due = 'o.total - (o.paid_amount - o.refunded_amount)';
        $rows = DB::table('orders as o')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.tenant_id', $this->tenant())
            ->whereNull('o.marketplace_account_id')
            ->whereNotIn('o.status', [OrderStatus::CANCELLED, OrderStatus::RETURNED])
            ->whereRaw("{$due} > 0")
            ->groupBy('o.customer_id', 'c.ulid', 'c.code', 'c.name', 'c.phone_last4')
            ->selectRaw("c.ulid, c.code, c.name, c.phone_last4, COUNT(*) as orders, SUM(o.total) as total, SUM(o.paid_amount - o.refunded_amount) as paid, SUM({$due}) as due, MIN(o.placed_at) as oldest")
            ->orderByDesc('due')->limit(2000)->get()
            ->map(fn ($r) => [
                'customer' => $r->ulid ? ['id' => $r->ulid, 'code' => $r->code, 'name' => $r->name, 'phone_last4' => $r->phone_last4] : null,
                'orders' => (int) $r->orders,
                'total' => round((float) $r->total, 2),
                'paid' => round((float) $r->paid, 2),
                'due' => round((float) $r->due, 2),
                'oldest_at' => $r->oldest ? CarbonImmutable::parse($r->oldest)->toIso8601String() : null,
                'age_days' => $r->oldest ? (int) CarbonImmutable::parse($r->oldest)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()) : null,
            ])->all();

        return ['rows' => $rows, 'totals' => ['customers' => count($rows), 'orders' => array_sum(array_column($rows, 'orders')), 'due' => round(array_sum(array_column($rows, 'due')), 2)]];
    }

    /**
     * Suppliers with an open balance: goods received less goods returned less payments.
     *
     * @return array<string, mixed>
     */
    public function payables(): array
    {
        $rows = Supplier::query()->withBalance()->having('debt', '!=', 0)->orderByDesc('debt')->limit(2000)->get()
            ->map(fn (Supplier $s) => [
                'supplier' => ['id' => $s->ulid, 'code' => $s->code, 'name' => $s->name, 'phone' => $s->phone],
                'orders' => (int) $s->orders_count,
                'purchased' => round((float) $s->purchased, 2),
                'paid' => round((float) $s->paid, 2),
                'debt' => round((float) $s->debt, 2),
            ])->all();

        return ['rows' => $rows, 'totals' => ['suppliers' => count($rows), 'debt' => round(array_sum(array_column($rows, 'debt')), 2)]];
    }

    /** @return array<string, float|int> */
    private function period(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = DB::table('orders')->where('tenant_id', $this->tenant())->whereBetween('placed_at', [$from, $to])->where('status', '!=', OrderStatus::CANCELLED)
            ->selectRaw('COALESCE(SUM(subtotal), 0) as goods, COALESCE(SUM(discount), 0) as discount, COALESCE(SUM(shipping_fee), 0) as shipping')->first();
        $cost = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.tenant_id', $this->tenant())->whereBetween('o.placed_at', [$from, $to])->where('o.status', '!=', OrderStatus::CANCELLED)
            ->selectRaw('COALESCE(SUM(i.cost_snapshot * i.quantity), 0) as cogs, COALESCE(SUM(CASE WHEN i.cost_snapshot IS NULL AND o.status IN (\''.implode("','", OrderStatus::SHIPPED).'\') THEN 1 ELSE 0 END), 0) as missing')
            ->first();
        // Marketplace returns often carry no refund amount: count the goods value of their lines instead.
        $lineValue = DB::table('order_return_lines as l')->join('order_items as i', 'i.id', '=', 'l.order_item_id')
            ->groupBy('l.order_return_id')->selectRaw('l.order_return_id, SUM(l.quantity * i.unit_price) as value');
        $returns = (float) DB::table('order_returns as r')->leftJoinSub($lineValue, 'lv', 'lv.order_return_id', '=', 'r.id')
            ->where('r.tenant_id', $this->tenant())->where('r.status', '!=', OrderReturn::REJECTED)->whereBetween('r.created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(COALESCE(r.refund_amount, lv.value, 0)), 0) as v')->value('v');

        $value = fn (array $types, ?string $extra = null) => (float) DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->where('m.tenant_id', $this->tenant())->whereBetween('m.created_at', [$from, $to])->whereIn('m.type', $types)
            ->when($extra, fn ($q) => $q->whereRaw($extra))
            ->selectRaw('COALESCE(SUM(m.on_hand_delta * COALESCE(m.unit_cost, v.cost_price)), 0) as v')->value('v');
        $writeOffReasons = "m.reason IN ('".implode("','", BusinessReports::WRITE_OFF_REASONS)."')";

        $cogsBack = $value(BusinessReports::STOCK_COLUMNS['return_in']);
        $writeOff = -$value(BusinessReports::STOCK_COLUMNS['damage_out']) - $value(['ADJUSTMENT_OUT'], $writeOffReasons);
        $stocktake = -$value(['ADJUSTMENT_OUT'], "(m.reason IS NULL OR NOT {$writeOffReasons})") - $value(BusinessReports::STOCK_COLUMNS['adjust_in']);

        $p = [
            'goods' => (float) $orders->goods,
            'discount' => (float) $orders->discount,
            'returns' => $returns,
            'shipping' => (float) $orders->shipping,
            'cogs_out' => (float) $cost->cogs,
            'cogs_back' => $cogsBack,
            'write_off' => $writeOff,
            'stocktake' => $stocktake,
        ];
        $p['revenue'] = $p['goods'] - $p['discount'] - $p['returns'] + $p['shipping'];
        $p['cogs'] = $p['cogs_out'] - $p['cogs_back'];
        $p['gross_profit'] = $p['revenue'] - $p['cogs'];
        $p['stock_loss'] = $p['write_off'] + $p['stocktake'];
        $p['profit'] = $p['gross_profit'] - $p['stock_loss'];

        // + 0.0 turns a negated zero (-0) into 0 so it never shows as "-0 đ".
        return [...array_map(fn ($v) => round($v, 2) + 0.0, $p), 'missing_cost_lines' => (int) $cost->missing];
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
