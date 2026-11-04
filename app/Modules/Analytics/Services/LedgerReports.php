<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\ReturnValues;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Services\CustomerDebt;
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
     * ledger cost captured at shipping less the cost (as shipped) of goods put back on the shelf from approved returns. Other income and
     * expenses are the receipt and payment vouchers whose type counts in profit and loss. Marketplace fees are not in
     * the system yet, so they are not here.
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
            ['other_income', 'V. Thu nhập khác (phiếu thu)', 0, 'cash'],
            ['other_expenses', 'VI. Chi phí khác (phiếu chi)', 0, 'cash'],
            ['profit', 'Lợi nhuận (III - IV + V - VI)', 0, null],
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
     * What each customer owes, by the same rule as the customer page (CustomerDebt). Walk-in buyers (no customer) are
     * one line, and there an order paid in excess cannot offset another buyer's debt.
     *
     * @return array<string, mixed>
     */
    public function receivables(): array
    {
        $due = CustomerDebt::dueSql('o');
        $returned = CustomerDebt::orderReturnsSql('o.id');
        $owed = "CASE WHEN o.customer_id IS NULL THEN GREATEST({$due}, 0) ELSE {$due} END";
        $rows = CustomerDebt::constrain(DB::table('orders as o'), 'o')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.tenant_id', $this->tenant())
            ->groupBy('o.customer_id', 'c.ulid', 'c.code', 'c.name', 'c.phone_last4')
            ->selectRaw("c.ulid, c.code, c.name, c.phone_last4, SUM(CASE WHEN {$due} > 0 THEN 1 ELSE 0 END) as orders, SUM(o.total - {$returned}) as total, "
                ."SUM(o.paid_amount - o.refunded_amount) as paid, SUM({$owed}) as due, MIN(CASE WHEN {$due} > 0 THEN o.placed_at END) as oldest")
            ->get()->keyBy(fn ($r) => $r->ulid ?? '');
        // Opening balances and adjustments of customers, with or without orders.
        $entries = DB::table('customers as c')->where('c.tenant_id', $this->tenant())
            ->whereExists(fn ($q) => $q->from('customer_debt_entries as x')->whereColumn('x.customer_id', 'c.id'))
            ->selectRaw('c.ulid, c.code, c.name, c.phone_last4, '.CustomerDebt::entriesSql('c.id').' as balance, '
                .'(SELECT MIN(x.entry_date) FROM customer_debt_entries x WHERE x.customer_id = c.id AND x.voided_at IS NULL AND x.amount > 0) as since')
            ->get();
        foreach ($entries as $e) {
            $row = $rows[$e->ulid] ?? (object) ['ulid' => $e->ulid, 'code' => $e->code, 'name' => $e->name, 'phone_last4' => $e->phone_last4, 'orders' => 0, 'total' => 0, 'paid' => 0, 'due' => 0, 'oldest' => null];
            $row->total += (float) $e->balance;
            $row->due += (float) $e->balance;
            if ($e->since && (float) $e->balance > 0 && (! $row->oldest || $e->since < $row->oldest)) {
                $row->oldest = $e->since;
            }
            $rows[$e->ulid] = $row;
        }

        $rows = $rows->filter(fn ($r) => (float) $r->due > 0.004)->sortByDesc(fn ($r) => (float) $r->due)->take(2000)->values();
        $overdue = DB::table('customers as c')->where('c.tenant_id', $this->tenant())->whereIn('c.ulid', $rows->pluck('ulid')->filter()->all())
            ->selectRaw('c.ulid, '.CustomerDebt::customerOverdueSql('c').' as overdue')->pluck('overdue', 'ulid');
        $rows = $rows->map(fn ($r) => [
            'customer' => $r->ulid ? ['id' => $r->ulid, 'code' => $r->code, 'name' => $r->name, 'phone_last4' => $r->phone_last4] : null,
            'orders' => (int) $r->orders,
            'total' => round((float) $r->total, 2),
            'paid' => round((float) $r->paid, 2),
            'due' => round((float) $r->due, 2),
            'overdue' => round((float) ($overdue[$r->ulid ?? ''] ?? 0), 2),
            'oldest_at' => $r->oldest ? CarbonImmutable::parse($r->oldest)->toIso8601String() : null,
            'age_days' => $r->oldest ? (int) CarbonImmutable::parse($r->oldest)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()) : null,
        ])->all();

        return ['rows' => $rows, 'totals' => ['customers' => count($rows), 'orders' => array_sum(array_column($rows, 'orders')), 'due' => round(array_sum(array_column($rows, 'due')), 2), 'overdue' => round(array_sum(array_column($rows, 'overdue')), 2)]];
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
            ->selectRaw('COALESCE(SUM(i.cost_snapshot * i.quantity), 0) as cogs, COALESCE(SUM(CASE WHEN i.cost_snapshot IS NULL AND i.mapping_status <> \'service\' AND o.status IN (\''.implode("','", OrderStatus::SHIPPED).'\') THEN 1 ELSE 0 END), 0) as missing')
            ->first();
        // Returns of orders still in revenue (an order cancelled after shipping is out of revenue and cost already).
        // Marketplace returns often carry no refund amount: their goods value counts instead (ReturnValues).
        $returnRows = fn () => DB::table('order_returns as r')->join('orders as ro', 'ro.id', '=', 'r.order_id')
            ->leftJoinSub(ReturnValues::lines(), 'rv', 'rv.order_return_id', '=', 'r.id')
            ->where('r.tenant_id', $this->tenant())->whereIn('r.status', ReturnValues::COUNTED)->where('ro.status', '!=', OrderStatus::CANCELLED);
        $returns = (float) $returnRows()->whereBetween('r.created_at', [$from, $to])->selectRaw('COALESCE(SUM('.ReturnValues::valueSql().'), 0) as v')->value('v');
        // Goods put back on the shelf, at the cost captured when they shipped.
        $cogsBack = (float) $returnRows()->whereBetween('r.inspected_at', [$from, $to])->selectRaw('COALESCE(SUM(rv.cost_back), 0) as v')->value('v');

        $value = fn (array $types, ?string $extra = null) => (float) DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->where('m.tenant_id', $this->tenant())->whereBetween('m.created_at', [$from, $to])->whereIn('m.type', $types)
            ->when($extra, fn ($q) => $q->whereRaw($extra))
            ->selectRaw('COALESCE(SUM(m.on_hand_delta * COALESCE(m.unit_cost, v.cost_price)), 0) as v')->value('v');
        $writeOffReasons = "m.reason IN ('".implode("','", BusinessReports::WRITE_OFF_REASONS)."')";

        $writeOff = -$value(BusinessReports::STOCK_COLUMNS['damage_out']) - $value(['ADJUSTMENT_OUT'], $writeOffReasons);
        $stocktake = -$value(['ADJUSTMENT_OUT'], "(m.reason IS NULL OR NOT {$writeOffReasons})") - $value(BusinessReports::STOCK_COLUMNS['adjust_in']);

        $other = DB::table('cash_vouchers')->where('tenant_id', $this->tenant())->whereNull('voided_at')->where('counts_in_pnl', true)->whereBetween('paid_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'receipt' THEN amount ELSE 0 END), 0) as income, COALESCE(SUM(CASE WHEN direction = 'payment' THEN amount ELSE 0 END), 0) as expenses")
            ->first();

        $p = [
            'goods' => (float) $orders->goods,
            'discount' => (float) $orders->discount,
            'returns' => $returns,
            'shipping' => (float) $orders->shipping,
            'cogs_out' => (float) $cost->cogs,
            'cogs_back' => $cogsBack,
            'write_off' => $writeOff,
            'stocktake' => $stocktake,
            'other_income' => (float) $other->income,
            'other_expenses' => (float) $other->expenses,
        ];
        $p['revenue'] = $p['goods'] - $p['discount'] - $p['returns'] + $p['shipping'];
        $p['cogs'] = $p['cogs_out'] - $p['cogs_back'];
        $p['gross_profit'] = $p['revenue'] - $p['cogs'];
        $p['stock_loss'] = $p['write_off'] + $p['stocktake'];
        $p['profit'] = $p['gross_profit'] - $p['stock_loss'] + $p['other_income'] - $p['other_expenses'];

        // + 0.0 turns a negated zero (-0) into 0 so it never shows as "-0 đ".
        return [...array_map(fn ($v) => round($v, 2) + 0.0, $p), 'missing_cost_lines' => (int) $cost->missing];
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
