<?php

namespace App\Modules\Staff\Services;

use App\Modules\Accounting\Support\HouseholdTax;
use App\Modules\Analytics\Support\ReturnValues;
use App\Modules\Analytics\Support\RevenuePolicy;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Models\CommissionRule;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hoa hồng of a month, from the business's own orders (not marketplace ones), counted when the revenue is recognised
 * (RevenuePolicy, like every report) and credited to the member the order belongs to (assigned_to: the cashier at the
 * counter, the seller otherwise). Approved returns of the month come off, so a returned sale gives its commission back.
 */
class CommissionService
{
    public function __construct(private readonly TenantContext $context, private readonly StaffDirectory $staff) {}

    /** @return array<string, mixed> */
    public function month(string $period, ?int $onlyUser = null): array
    {
        [$from, $to, $label] = HouseholdTax::bounds($period);
        $rules = CommissionRule::query()->orderBy('id')->get();
        $sales = $this->sales($from, $to);
        $storeRevenue = array_sum(array_map(fn ($s) => $s['counter'] + $s['online'], $sales));
        $units = [];
        foreach ($rules as $rule) {
            if ($rule->kind === 'category_unit' && $rule->is_active) {
                $units[$rule->id] = $this->units($rule, $from, $to);
            }
        }

        $rows = [];
        foreach ($this->staff->members($onlyUser) as $m) {
            $uid = $m['user_id'];
            $own = $sales[$uid] ?? ['counter' => 0.0, 'online' => 0.0];
            $items = [];
            foreach ($rules as $rule) {
                if (! $rule->is_active || ! $rule->appliesTo($uid)) {
                    continue;
                }
                [$base, $amount, $unit] = match ($rule->kind) {
                    'counter' => [$own['counter'], $own['counter'] * (float) $rule->rate / 100, 'money'],
                    'online' => [$own['online'], $own['online'] * (float) $rule->rate / 100, 'money'],
                    'category_unit' => [$units[$rule->id][$uid] ?? 0, ($units[$rule->id][$uid] ?? 0) * (float) $rule->amount, 'units'],
                    default => $storeRevenue > (float) $rule->threshold
                        ? [$own['counter'] + $own['online'], ($own['counter'] + $own['online']) * (float) $rule->rate / 100, 'money']
                        : [0.0, 0.0, 'money'],
                };
                if ($base != 0 || $amount != 0) {
                    $items[] = ['rule' => $rule->ulid, 'name' => $rule->name, 'kind' => $rule->kind, 'base' => round($base), 'unit' => $unit, 'amount' => round($amount)];
                }
            }
            $target = (float) ($m['profile']->sales_target ?? 0);
            $total = $own['counter'] + $own['online'];
            $rows[] = [
                'id' => $m['id'], 'user_id' => $uid, 'name' => $m['name'], 'title' => $m['profile']->title ?? $m['role'],
                'sales' => round($total), 'commission' => round(array_sum(array_column($items, 'amount'))), 'items' => $items,
                'target' => $target, 'target_pct' => $target > 0 ? round($total / $target * 100) : null,
            ];
        }

        return [
            'period' => $period, 'label' => $label, 'store_revenue' => round($storeRevenue),
            'store_counter' => round(array_sum(array_column($sales, 'counter'))), 'store_online' => round(array_sum(array_column($sales, 'online'))),
            'weeks' => $onlyUser === null ? $this->weeks($from, $to) : [],
            'members' => $rows,
        ];
    }

    /**
     * The store's own-channel sales by week of the month (1–7, 8–14, 15–21, 22–end), returns off.
     *
     * @return list<array{label: string, from: string, to: string, amount: float}>
     */
    private function weeks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach ([[1, 7], [8, 14], [15, 21], [22, (int) $to->day]] as $i => [$a, $b]) {
            $start = $from->setDay($a)->startOfDay();
            $end = $from->setDay($b)->endOfDay();
            $sales = $this->sales($start, $end);
            $out[] = [
                'label' => 'Tuần '.($i + 1), 'from' => $start->toDateString(), 'to' => $end->toDateString(),
                'amount' => round(array_sum(array_map(fn ($s) => $s['counter'] + $s['online'], $sales))),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function ruleView(CommissionRule $r): array
    {
        $userUlids = $r->user_ids ? DB::table('users')->whereIn('id', $r->user_ids)->pluck('ulid')->all() : null;

        return [
            'id' => $r->ulid, 'name' => $r->name, 'kind' => $r->kind, 'rate' => (float) $r->rate, 'amount' => (float) $r->amount,
            'category_id' => $r->category_id ? DB::table('categories')->where('id', $r->category_id)->value('ulid') : null,
            'category' => $r->category_id ? DB::table('categories')->where('id', $r->category_id)->value('name') : null,
            'threshold' => (float) $r->threshold, 'user_ids' => $userUlids, 'is_active' => $r->is_active,
        ];
    }

    /**
     * Goods value (after discounts, before shipping) of each member's own orders, counter and other, returns off.
     *
     * @return array<int, array{counter: float, online: float}>
     */
    private function sales($from, $to): array
    {
        $out = [];
        $orders = RevenuePolicy::recognized(DB::table('orders as o')->where('o.tenant_id', $this->context->id())->where('o.marketplace', Order::SOURCE_MANUAL), 'o', $from, $to)
            ->whereNotNull('o.assigned_to')
            ->groupBy('o.assigned_to', DB::raw('o.cash_shift_id IS NOT NULL'))
            ->selectRaw('o.assigned_to as uid, (o.cash_shift_id IS NOT NULL) as counter, SUM(o.subtotal - o.discount) as amount')->get();
        foreach ($orders as $r) {
            $out[(int) $r->uid] ??= ['counter' => 0.0, 'online' => 0.0];
            $out[(int) $r->uid][$r->counter ? 'counter' : 'online'] += (float) $r->amount;
        }
        $returns = $this->returned($from, $to)
            ->groupBy('ro.assigned_to', DB::raw('ro.cash_shift_id IS NOT NULL'))
            ->selectRaw('ro.assigned_to as uid, (ro.cash_shift_id IS NOT NULL) as counter, SUM(CASE WHEN ri.quantity > 0 THEN rl.quantity * (ri.unit_price * ri.quantity - ri.discount) / ri.quantity ELSE 0 END) as amount')->get();
        foreach ($returns as $r) {
            $out[(int) $r->uid] ??= ['counter' => 0.0, 'online' => 0.0];
            $out[(int) $r->uid][$r->counter ? 'counter' : 'online'] -= (float) $r->amount;
        }

        return $out;
    }

    /** @return array<int, float> units of the rule's category (and its sub-categories) sold by each member, returns off */
    private function units(CommissionRule $rule, $from, $to): array
    {
        $cats = $this->categoryTree($rule->category_id);
        if ($cats === []) {
            return [];
        }
        $out = [];
        $sold = RevenuePolicy::recognized(DB::table('orders as o')->where('o.tenant_id', $this->context->id())->where('o.marketplace', Order::SOURCE_MANUAL), 'o', $from, $to)
            ->join('order_items as i', 'i.order_id', '=', 'o.id')->join('product_variants as v', 'v.id', '=', 'i.product_variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('p.category_id', $cats)->whereNotNull('o.assigned_to')
            ->groupBy('o.assigned_to')->selectRaw('o.assigned_to as uid, SUM(i.quantity) as qty')->get();
        foreach ($sold as $r) {
            $out[(int) $r->uid] = (float) $r->qty;
        }
        $back = $this->returned($from, $to)->join('product_variants as v', 'v.id', '=', 'ri.product_variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('p.category_id', $cats)->groupBy('ro.assigned_to')->selectRaw('ro.assigned_to as uid, SUM(rl.quantity) as qty')->get();
        foreach ($back as $r) {
            $out[(int) $r->uid] = ($out[(int) $r->uid] ?? 0) - (float) $r->qty;
        }

        return $out;
    }

    /** Approved return lines of the month, of the business's own recognised orders. */
    private function returned($from, $to): Builder
    {
        return RevenuePolicy::recognized(DB::table('order_returns as r')->join('orders as ro', 'ro.id', '=', 'r.order_id'), 'ro')
            ->join('order_return_lines as rl', 'rl.order_return_id', '=', 'r.id')->join('order_items as ri', 'ri.id', '=', 'rl.order_item_id')
            ->where('r.tenant_id', $this->context->id())->where('ro.marketplace', Order::SOURCE_MANUAL)->whereNotNull('ro.assigned_to')
            ->whereIn('r.status', ReturnValues::COUNTED)->whereBetween('r.created_at', [$from, $to]);
    }

    /** @return array<int, int> the category and every category under it */
    private function categoryTree(?int $root): array
    {
        if ($root === null) {
            return [];
        }
        $all = DB::table('categories')->where('tenant_id', $this->context->id())->get(['id', 'parent_id']);
        $ids = [$root];
        for ($i = 0; $i < count($ids) && $i < 1000; $i++) {
            foreach ($all as $c) {
                if ((int) $c->parent_id === $ids[$i] && ! in_array((int) $c->id, $ids, true)) {
                    $ids[] = (int) $c->id;
                }
            }
        }

        return $ids;
    }
}
