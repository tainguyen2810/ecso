<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\ProfitFigures;
use App\Modules\Analytics\Support\RevenuePolicy;
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
     * Profit and loss for the range and for the period of the same length just before it, from ProfitFigures (the
     * same figures as the finance page): revenue recognised by RevenuePolicy, approved returns, cost as shipped,
     * marketplace fees confirmed or estimated, shipping charged to buyers on its own line, vouchers by profit group.
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
            ['revenue', 'I. Doanh thu thuần (1 - 2 - 3 - 4)', 0, 'sales'],
            ['goods', '1. Tiền hàng bán ra', 1, 'sales'],
            ['discount', '2. Chiết khấu', 1, 'sales'],
            ['sales_tax', '3. Thuế GTGT nằm trong giá bán', 1, null],
            ['returns', '4. Hàng trả lại (chưa gồm thuế GTGT)', 1, 'returns'],
            ['cogs', 'II. Giá vốn hàng bán (4 - 5)', 0, null],
            ['cogs_out', '4. Giá vốn hàng xuất bán', 1, 'products'],
            ['cogs_back', '5. Giá vốn hàng trả lại nhập kho', 1, 'returns'],
            ['gross_profit', 'III. Lợi nhuận gộp (I - II)', 0, null],
            ['marketplace_fees', 'IV. Phí sàn (6 + 7)', 0, 'finance'],
            ['fees_confirmed', '6. Đã xác nhận (file đối soát)', 1, 'finance'],
            ['fees_estimated', '7. Ước tính theo bảng phí', 1, 'finance'],
            ['shipping', 'V. Phí giao hàng thu của khách', 0, 'sales'],
            ['selling_expenses', 'VI. Chi phí bán hàng (phiếu chi)', 0, 'cash'],
            ['contribution_profit', 'Lợi nhuận đóng góp (III - IV + V - VI)', 0, null],
            ['stock_loss', 'VII. Hao hụt kho (8 + 9)', 0, null],
            ['write_off', '8. Hàng hủy, mất', 1, 'stock'],
            ['stocktake', '9. Chênh lệch kiểm kê, điều chỉnh', 1, 'stock'],
            ['operating_expenses', 'VIII. Chi phí vận hành (phiếu chi)', 0, 'cash'],
            ['misc_expenses', 'IX. Chi phí khác (phiếu chi)', 0, 'cash'],
            ['other_income', 'X. Thu nhập khác (phiếu thu)', 0, 'cash'],
            ['profit', 'Lợi nhuận quản trị ước tính (đóng góp - VII - VIII - IX + X)', 0, null],
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
            'fees_unknown_orders' => $current['fees_unknown_orders'],
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
            ->havingRaw("SUM({$owed}) > 0")
            ->selectRaw("c.ulid, c.code, c.name, c.phone_last4, SUM(CASE WHEN {$due} > 0 THEN 1 ELSE 0 END) as orders, SUM(o.total - {$returned}) as total, "
                ."SUM(o.paid_amount - o.refunded_amount) as paid, SUM({$owed}) as due, MIN(CASE WHEN {$due} > 0 THEN o.placed_at END) as oldest")
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

    /** @return array<string, float|int|null> */
    private function period(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return app(ProfitFigures::class)->period($from, $to);
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
