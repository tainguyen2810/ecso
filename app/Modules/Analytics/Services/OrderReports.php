<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Support\RevenuePolicy;
use App\Modules\Analytics\Support\Search;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Purchasing\Services\OnOrderQuery;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Sapo-style sales reports at order and line level, the delivery status mix of the sales dashboard and the detailed
 * stock report (on hand, held, sellable, incoming). Same rules as the other reports: orders placed in the range,
 * cancelled and returned orders left out, cost of goods = the cost captured when the goods left the warehouse.
 */
class OrderReports
{
    private const EXCLUDED = [OrderStatus::CANCELLED, OrderStatus::RETURNED];

    public function __construct(private readonly TenantContext $context, private readonly OnOrderQuery $onOrder) {}

    /**
     * One row per order (Sapo "Thống kê theo đơn hàng").
     *
     * @param  array{q?: string|null, channel?: int|null, warehouse?: int|null, basis?: string}  $filters
     * @return array<string, mixed>
     */
    public function byOrder(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $items = DB::table('order_items')->where('tenant_id', $this->tenant())->groupBy('order_id')
            ->selectRaw('order_id, SUM(quantity) as units, SUM(quantity * unit_price) as goods, SUM(discount) as line_discount, SUM(COALESCE(cost_snapshot, 0) * quantity) as cogs');

        $base = $this->orders($from, $to, $filters)->leftJoinSub($items, 'it', 'it.order_id', '=', 'o.id');
        // Totals over every order in the range, not only the rows shown (the table stops at the row limit).
        $all = (clone $base)->selectRaw('COUNT(*) as orders, COALESCE(SUM(it.units), 0) as units, COALESCE(SUM(it.goods), 0) as goods, '
            .'COALESCE(SUM(COALESCE(it.line_discount, 0) + o.discount), 0) as discount, COALESCE(SUM(o.shipping_fee), 0) as shipping_fee, COALESCE(SUM(o.total), 0) as total, '
            .'COALESCE(SUM(it.cogs), 0) as cogs')->first();
        $rows = (clone $base)
            ->orderByDesc('o.placed_at')->limit(self::limit())
            ->get(['o.ulid', 'o.external_order_id', 'o.placed_at', 'o.status', 'o.total', 'o.shipping_fee', 'o.discount', 'o.buyer_name', 'c.name as customer',
                DB::raw("COALESCE(ma.shop_name, sc.name, 'Khác') as channel"), 'it.units', 'it.goods', 'it.line_discount', 'it.cogs'])
            ->map(function ($r) {
                $revenue = (float) $r->total - (float) $r->shipping_fee;
                $cogs = (float) $r->cogs;

                return [
                    'id' => $r->ulid,
                    'number' => $r->external_order_id,
                    'placed_at' => CarbonImmutable::parse($r->placed_at)->toIso8601String(),
                    'status' => $r->status,
                    'customer' => $r->customer ?? $this->buyer($r->buyer_name),
                    'channel' => $r->channel,
                    'units' => (int) $r->units,
                    'goods' => round((float) $r->goods, 2),
                    'discount' => round((float) $r->line_discount + (float) $r->discount, 2),
                    'shipping_fee' => round((float) $r->shipping_fee, 2),
                    'total' => round((float) $r->total, 2),
                    'cogs' => round($cogs, 2),
                    'gross_profit' => round($revenue - $cogs, 2),
                ];
            })->all();

        $totals = [
            'orders' => (int) $all->orders,
            'units' => (int) $all->units,
            'goods' => round((float) $all->goods, 2),
            'discount' => round((float) $all->discount, 2),
            'shipping_fee' => round((float) $all->shipping_fee, 2),
            'total' => round((float) $all->total, 2),
            'cogs' => round((float) $all->cogs, 2),
            'gross_profit' => round((float) $all->total - (float) $all->shipping_fee - (float) $all->cogs, 2),
        ];

        return ['range' => $this->range($from, $to), 'basis' => $filters['basis'] ?? 'placed', 'rows' => $rows, 'totals' => $totals, ...$this->truncation(count($rows), $totals['orders'])];
    }

    /**
     * One row per order line (Sapo "Báo cáo bán hàng chi tiết").
     *
     * @param  array{q?: string|null, channel?: int|null, warehouse?: int|null, basis?: string}  $filters
     * @return array<string, mixed>
     */
    public function lines(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $q = $filters['q'] ?? null;
        $like = $q === null ? null : Search::contains($q);
        $base = $this->orders($from, $to, ['channel' => $filters['channel'] ?? null, 'warehouse' => $filters['warehouse'] ?? null])
            ->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->when($like, fn (Builder $w) => $w->where(fn (Builder $s) => $s->where('o.external_order_id', 'like', $like)
                ->orWhere('v.sku', 'like', $like)->orWhere('i.name', 'like', $like)->orWhere('c.name', 'like', $like)));
        $all = (clone $base)->selectRaw('COUNT(*) as line_count, COUNT(DISTINCT o.id) as orders, COALESCE(SUM(i.quantity), 0) as quantity, COALESCE(SUM(i.discount), 0) as discount, '
            .'COALESCE(SUM(i.quantity * i.unit_price - i.discount), 0) as amount, COALESCE(SUM(COALESCE(i.cost_snapshot, 0) * i.quantity), 0) as cogs')->first();
        $rows = (clone $base)
            ->orderByDesc('o.placed_at')->orderBy('i.id')->limit(self::limit())
            ->get(['o.ulid as order_id', 'o.external_order_id', 'o.placed_at', 'o.buyer_name', 'c.name as customer', DB::raw("COALESCE(ma.shop_name, sc.name, 'Khác') as channel"),
                'i.name', 'i.quantity', 'i.unit_price', 'i.discount', 'i.cost_snapshot', 'i.mapping_status', 'v.ulid as variant_id', 'v.sku', 'p.name as product_name', 'v.name as variant_name'])
            ->map(function ($r) {
                $amount = (float) $r->quantity * (float) $r->unit_price - (float) $r->discount;
                $cogs = (float) ($r->cost_snapshot ?? 0) * (int) $r->quantity;

                return [
                    'order' => ['id' => $r->order_id, 'number' => $r->external_order_id],
                    'placed_at' => CarbonImmutable::parse($r->placed_at)->toIso8601String(),
                    'customer' => $r->customer ?? $this->buyer($r->buyer_name),
                    'channel' => $r->channel,
                    'product' => [
                        'id' => $r->variant_id,
                        'sku' => $r->sku,
                        'name' => $r->variant_id ? trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : '')) : $r->name,
                        'service' => $r->mapping_status === 'service',
                    ],
                    'quantity' => (int) $r->quantity,
                    'unit_price' => round((float) $r->unit_price, 2),
                    'discount' => round((float) $r->discount, 2),
                    'amount' => round($amount, 2),
                    'cogs' => round($cogs, 2),
                    'gross_profit' => round($amount - $cogs, 2),
                ];
            })->all();

        $totals = [
            'lines' => (int) $all->line_count,
            'orders' => (int) $all->orders,
            'quantity' => (int) $all->quantity,
            'discount' => round((float) $all->discount, 2),
            'amount' => round((float) $all->amount, 2),
            'cogs' => round((float) $all->cogs, 2),
            'gross_profit' => round((float) $all->amount - (float) $all->cogs, 2),
        ];

        return ['range' => $this->range($from, $to), 'basis' => $filters['basis'] ?? 'placed', 'rows' => $rows, 'totals' => $totals, ...$this->truncation(count($rows), $totals['lines'])];
    }

    /**
     * Orders placed in the range by where they are in delivery (Sapo "Thông tin giao hàng"). The groups do not overlap
     * and together count every order placed in the range.
     *
     * @return array<int, array{key: string, label: string, orders: int}>
     */
    public function delivery(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $groups = [
            'delivered' => ['Đã giao', [OrderStatus::DELIVERED, OrderStatus::COMPLETED]],
            'shipping' => ['Đang giao', [OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT]],
            'waiting' => ['Chờ xử lý, chờ lấy hàng', [OrderStatus::UNPAID, OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK, OrderStatus::PACKED]],
            'return_requested' => ['Yêu cầu trả hàng', [OrderStatus::RETURN_REQUESTED]],
            'failed' => ['Hủy, hoàn, giao thất bại', [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::FAILED_DELIVERY]],
            // Statuses not mapped yet (UNKNOWN_STATUS or a new one), so the groups always add up to every order.
            'unknown' => ['Trạng thái chưa rõ', []],
        ];
        $known = array_merge(...array_column($groups, 1));
        $counts = DB::table('orders')->where('tenant_id', $this->tenant())->whereBetween('placed_at', [$from, $to])
            ->groupBy('status')->pluck(DB::raw('COUNT(*)'), 'status');

        return collect($groups)->map(fn ($g, $key) => [
            'key' => $key,
            'label' => $g[0],
            'orders' => $key === 'unknown'
                ? (int) collect($counts)->except($known)->sum()
                : (int) collect($g[1])->sum(fn ($s) => (int) ($counts[$s] ?? 0)),
        ])->values()->all();
    }

    /**
     * Stock per SKU and warehouse right now: on hand, held for orders, sellable, on order from suppliers, value.
     *
     * @return array<string, mixed>
     */
    public function stockDetail(?int $warehouseId = null, ?string $q = null): array
    {
        $base = DB::table('stock_balances as b')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.tenant_id', $this->tenant())
            ->whereNull('v.deleted_at')
            ->when($warehouseId, fn (Builder $w) => $w->where('b.warehouse_id', $warehouseId))
            ->when($q, fn (Builder $w) => $w->where(fn (Builder $s) => $s->where('v.sku', 'like', Search::contains($q))->orWhere('p.name', 'like', Search::contains($q))))
            ->where(fn (Builder $w) => $w->where('b.on_hand', '!=', 0)->orWhere('b.reserved', '!=', 0));
        $all = (clone $base)->selectRaw('COUNT(*) as n, COUNT(DISTINCT b.product_variant_id) as skus, COALESCE(SUM(b.on_hand), 0) as on_hand, COALESCE(SUM(b.reserved), 0) as reserved, '
            .'COALESCE(SUM(GREATEST(0, CAST(b.on_hand AS SIGNED) - CAST(b.reserved AS SIGNED) - CAST(b.safety_stock AS SIGNED))), 0) as sellable, COALESCE(SUM(b.on_hand * v.cost_price), 0) as value')->first();
        $rows = (clone $base)
            ->orderBy('v.sku')->orderBy('w.name')->limit(self::limit())
            ->get(['v.id as vid', 'v.ulid', 'v.sku', 'v.name as variant_name', 'p.name as product_name', 'v.cost_price', 'w.id as wid', 'w.name as warehouse', 'b.on_hand', 'b.reserved', 'b.safety_stock']);

        $incoming = $this->onOrder->byVariantAndWarehouse($rows->pluck('vid')->unique()->map(fn ($id) => (int) $id)->values()->all());
        $out = $rows->map(function ($r) use ($incoming) {
            $available = (int) $r->on_hand - (int) $r->reserved;

            return [
                'variant' => ['id' => $r->ulid, 'sku' => $r->sku, 'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : ''))],
                'warehouse' => $r->warehouse,
                'on_hand' => (int) $r->on_hand,
                'reserved' => (int) $r->reserved,
                'available' => $available,
                'sellable' => max(0, $available - (int) $r->safety_stock),
                'incoming' => (int) ($incoming[$r->vid][$r->wid] ?? 0),
                'cost_price' => round((float) $r->cost_price, 2),
                'value' => round((int) $r->on_hand * (float) $r->cost_price, 2),
            ];
        })->all();

        // Goods on order count for every balance in the filter, not only the rows shown.
        $truncated = count($out) < (int) $all->n;
        if ($truncated) {
            $pairs = (clone $base)->get(['b.product_variant_id as vid', 'b.warehouse_id as wid']);
            $allIncoming = $this->onOrder->byVariantAndWarehouse($pairs->pluck('vid')->unique()->map(fn ($id) => (int) $id)->values()->all());
            $incomingTotal = (int) $pairs->sum(fn ($p) => (int) ($allIncoming[$p->vid][$p->wid] ?? 0));
        } else {
            $incomingTotal = (int) array_sum(array_column($out, 'incoming'));
        }

        return ['rows' => $out, 'totals' => [
            'skus' => (int) $all->skus,
            'on_hand' => (int) $all->on_hand,
            'reserved' => (int) $all->reserved,
            'available' => (int) $all->on_hand - (int) $all->reserved,
            'sellable' => (int) $all->sellable,
            'incoming' => $incomingTotal,
            'value' => round((float) $all->value, 2),
        ], ...$this->truncation(count($out), (int) $all->n)];
    }

    /** @param  array{q?: string|null, channel?: int|null, warehouse?: int|null, basis?: string}  $filters */
    private function orders(CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $like = isset($filters['q']) ? Search::contains($filters['q']) : null;

        $query = DB::table('orders as o')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'o.marketplace_account_id')
            ->leftJoin('sales_channels as sc', 'sc.id', '=', 'o.sales_channel_id')
            ->where('o.tenant_id', $this->tenant());
        $query = ($filters['basis'] ?? 'placed') === 'completed'
            ? RevenuePolicy::recognized($query, 'o', $from, $to)
            : $query->whereBetween('o.placed_at', [$from, $to])->whereNotIn('o.status', self::EXCLUDED);

        return $query
            ->when($filters['channel'] ?? null, fn (Builder $w, int $id) => $w->where('o.sales_channel_id', $id))
            ->when($filters['warehouse'] ?? null, fn (Builder $w, int $id) => $w->where('o.warehouse_id', $id))
            ->when($like, fn (Builder $w) => $w->where(fn (Builder $s) => $s->where('o.external_order_id', 'like', $like)->orWhere('c.name', 'like', $like)));
    }

    /** The buyer name is encrypted at rest (personal data); shown only when there is no customer record. */
    private function buyer(?string $encrypted): string
    {
        if (! $encrypted) {
            return 'Khách lẻ';
        }
        try {
            return Crypt::decryptString($encrypted) ?: 'Khách lẻ';
        } catch (DecryptException) {
            return 'Khách lẻ';
        }
    }

    private function tenant(): int
    {
        return $this->context->id();
    }

    private static function limit(): int
    {
        return (int) config('ecso.reports.row_limit', 2000);
    }

    /**
     * Tells the page when the table holds only the first rows while the totals cover everything.
     *
     * @return array{truncated: bool, total_rows: int, limit: int}
     */
    private function truncation(int $shown, int $all): array
    {
        return ['truncated' => $shown < $all, 'total_rows' => $all, 'limit' => self::limit()];
    }

    /** @return array{from: string, to: string} */
    private function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
