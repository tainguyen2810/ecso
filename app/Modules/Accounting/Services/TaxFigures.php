<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Support\HouseholdTax;
use App\Modules\Analytics\Support\ProfitFigures;
use App\Modules\Analytics\Support\ReturnValues;
use App\Modules\Analytics\Support\RevenuePolicy;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Doanh thu tính thuế của hộ kinh doanh, theo ngày, kênh và nhóm ngành:
 *  - cùng mốc ghi nhận doanh thu với các báo cáo (RevenuePolicy): kênh riêng khi giao, sàn khi sàn hoàn tất;
 *  - tiền khách trả cho hàng (sau chiết khấu, gồm thuế cộng thêm nếu có), phí ship chỉ khi bật trong Thiết lập thuế;
 *  - hàng trả lại đã duyệt trừ vào ngày tạo phiếu trả;
 *  - đơn sàn (Shopee, TikTok Shop, Lazada, Tiki…) tách riêng: sàn đã khấu trừ và nộp thay.
 * Nhóm ngành của một dòng: theo danh mục sản phẩm (hoặc danh mục cha) trong Thiết lập thuế, còn lại là nhóm mặc định.
 */
class TaxFigures
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<int, array{day: string, kind: string, channel: string, label: string, group: string, amount: float, orders: array<int, string>}>
     */
    public function entries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tax = HouseholdTax::current();
        $groupOf = $this->groupResolver($tax);
        $share = 'CASE WHEN o.subtotal > 0 THEN GREATEST(0, (o.subtotal - o.discount + CASE WHEN o.prices_include_tax = 0 THEN o.tax_amount ELSE 0 END) / o.subtotal) ELSE 0 END';
        $market = ProfitFigures::isMarketplaceSql('o');
        $day = ReportRange::day(RevenuePolicy::dateSql('o'), $to);
        $label = "COALESCE(ma.shop_name, sc.name, CASE WHEN o.marketplace = 'manual' THEN 'Đơn tự tạo' ELSE o.marketplace END)";

        $sales = RevenuePolicy::recognized(DB::table('orders as o')->where('o.tenant_id', $this->tenant()), 'o', $from, $to)
            ->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'o.sales_channel_id')
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'o.marketplace_account_id')
            ->groupBy('o.id', 'o.external_order_id', 'o.code', 'o.delivered_at', 'o.completed_at', 'o.marketplace', 'o.marketplace_account_id', 'o.sales_channel_id', 'p.category_id', 'ma.shop_name', 'sc.name')
            ->selectRaw("o.id, COALESCE(o.code, o.external_order_id) as number, {$day} as day, ({$market}) as is_market, CONCAT(o.marketplace, ':', COALESCE(o.marketplace_account_id, o.sales_channel_id, 0)) as channel, {$label} as label, p.category_id, "
                ."SUM((i.unit_price * i.quantity - i.discount) * ({$share})) as amount")
            ->get();

        $out = [];
        $add = function (string $day, bool $isMarket, string $channel, string $label, string $group, float $amount, ?string $number) use (&$out) {
            $key = "{$day}|{$channel}|{$group}";
            $out[$key] ??= ['day' => $day, 'kind' => $isMarket ? 'market' : 'self', 'channel' => $channel, 'label' => $label, 'group' => $group, 'amount' => 0.0, 'orders' => []];
            $out[$key]['amount'] += $amount;
            if ($number !== null && ! in_array($number, $out[$key]['orders'], true)) {
                $out[$key]['orders'][] = $number;
            }
        };
        foreach ($sales as $r) {
            $add((string) $r->day, (bool) $r->is_market, (string) $r->channel, (string) $r->label, $groupOf($r->category_id ? (int) $r->category_id : null), (float) $r->amount, (string) $r->number);
        }

        if ($tax->includeShipping()) {
            $ship = RevenuePolicy::recognized(DB::table('orders as o')->where('o.tenant_id', $this->tenant()), 'o', $from, $to)
                ->leftJoin('sales_channels as sc', 'sc.id', '=', 'o.sales_channel_id')
                ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'o.marketplace_account_id')
                ->where('o.shipping_fee', '>', 0)
                ->selectRaw("COALESCE(o.code, o.external_order_id) as number, {$day} as day, ({$market}) as is_market, CONCAT(o.marketplace, ':', COALESCE(o.marketplace_account_id, o.sales_channel_id, 0)) as channel, {$label} as label, o.shipping_fee as amount")
                ->get();
            foreach ($ship as $r) {
                $add((string) $r->day, (bool) $r->is_market, (string) $r->channel, (string) $r->label, $tax->defaultGroup(), (float) $r->amount, (string) $r->number);
            }
        }

        // Approved returns of recognised orders, on the day the return was made.
        $rday = ReportRange::day('r.created_at', $to);
        $rshare = str_replace('o.', 'ro.', $share);
        $returns = RevenuePolicy::recognized(DB::table('order_returns as r')->join('orders as ro', 'ro.id', '=', 'r.order_id'), 'ro')
            ->join('order_return_lines as rl', 'rl.order_return_id', '=', 'r.id')
            ->join('order_items as ri', 'ri.id', '=', 'rl.order_item_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'ri.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'ro.sales_channel_id')
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'ro.marketplace_account_id')
            ->where('r.tenant_id', $this->tenant())->whereIn('r.status', ReturnValues::COUNTED)->whereBetween('r.created_at', [$from, $to])
            ->groupBy('r.id', 'r.created_at', 'ro.external_order_id', 'ro.code', 'ro.marketplace', 'ro.marketplace_account_id', 'ro.sales_channel_id', 'p.category_id', 'ma.shop_name', 'sc.name')
            ->selectRaw("COALESCE(ro.code, ro.external_order_id) as number, {$rday} as day, (".ProfitFigures::isMarketplaceSql('ro').") as is_market, CONCAT(ro.marketplace, ':', COALESCE(ro.marketplace_account_id, ro.sales_channel_id, 0)) as channel, "
                .str_replace('o.marketplace', 'ro.marketplace', $label).' as label, p.category_id, '
                ."SUM(CASE WHEN ri.quantity > 0 THEN rl.quantity * (ri.unit_price * ri.quantity - ri.discount) / ri.quantity * ({$rshare}) ELSE 0 END) as amount")
            ->get();
        foreach ($returns as $r) {
            $add((string) $r->day, (bool) $r->is_market, 'return:'.$r->channel, 'Khách trả hàng · '.$r->label, $groupOf($r->category_id ? (int) $r->category_id : null), -(float) $r->amount, (string) $r->number);
        }

        $rows = array_values($out);
        usort($rows, fn ($a, $b) => [$a['day'], $a['kind'], $a['label']] <=> [$b['day'], $b['kind'], $b['label']]);

        return array_map(fn ($r) => [...$r, 'amount' => round($r['amount'])], $rows);
    }

    /**
     * Sums of entries(): by kind and group, and tax on the self-declared part.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function summarize(array $entries): array
    {
        $tax = HouseholdTax::current();
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        foreach (array_keys(HouseholdTax::GROUPS) as $g) {
            $groups[$g] = ['group' => $g, 'label' => HouseholdTax::GROUPS[$g], 'self' => 0.0, 'market' => 0.0, ...$tax->rates($g)];
        }
        foreach ($entries as $e) {
            $groups[$e['group']][$e['kind']] += $e['amount'];
        }
        $self = $market = $vat = $pit = $withheld = 0.0;
        foreach ($groups as $g => $row) {
            $row['vat_amount'] = round(max(0, $row['self']) * $row['vat'] / 100);
            $row['pit_amount'] = round(max(0, $row['self']) * $row['pit'] / 100);
            $row['withheld'] = round(max(0, $row['market']) * ($row['vat'] + $row['pit']) / 100);
            $groups[$g] = $row;
            $self += $row['self'];
            $market += $row['market'];
            $vat += $row['vat_amount'];
            $pit += $row['pit_amount'];
            $withheld += $row['withheld'];
        }

        return [
            'groups' => array_values($groups),
            'self' => round($self), 'market' => round($market), 'total' => round($self + $market),
            'vat' => $vat, 'pit' => $pit, 'tax' => $vat + $pit, 'withheld' => $withheld,
        ];
    }

    /** @return callable(?int): string */
    private function groupResolver(HouseholdTax $tax): callable
    {
        $byUlid = $tax->categoryGroups();
        $cats = DB::table('categories')->where('tenant_id', $this->tenant())->get(['id', 'ulid', 'parent_id'])->keyBy('id');
        $memo = [];

        return function (?int $categoryId) use ($tax, $byUlid, $cats, &$memo): string {
            if ($categoryId === null) {
                return $tax->defaultGroup();
            }
            if (isset($memo[$categoryId])) {
                return $memo[$categoryId];
            }
            $id = $categoryId;
            for ($depth = 0; $id !== null && $depth < 10; $depth++) {
                $c = $cats->get($id);
                if ($c === null) {
                    break;
                }
                if (isset($byUlid[$c->ulid])) {
                    return $memo[$categoryId] = $byUlid[$c->ulid];
                }
                $id = $c->parent_id ? (int) $c->parent_id : null;
            }

            return $memo[$categoryId] = $tax->defaultGroup();
        };
    }

    private function tenant(): int
    {
        return $this->context->tenant()->id;
    }
}
