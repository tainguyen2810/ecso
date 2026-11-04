<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Inventory\Services\CostHistory;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Day-to-day business reports across every sales channel (marketplace shops and the seller's own channels):
 * sales and gross profit by day / channel / staff / customer / warehouse, the stock in-out-balance ledger, and the cashbook.
 * Read-only; stock figures come straight from the immutable movement ledger.
 */
class BusinessReports
{
    /** Movement types grouped the way a stock in-out-balance report shows them. */
    public const STOCK_COLUMNS = [
        'purchase_in' => ['RECEIPT', 'OPENING'],
        'return_in' => ['RETURN_RESTOCK'],
        'transfer_in' => ['TRANSFER_IN'],
        'adjust_in' => ['ADJUSTMENT_IN'],
        'sale_out' => ['SHIP', 'SHIP_UNRESERVED'],
        'supplier_out' => ['SUPPLIER_RETURN'],
        'damage_out' => ['DAMAGE_OUT'],
        'adjust_out' => ['ADJUSTMENT_OUT'],
        'transfer_out' => ['TRANSFER_OUT'],
    ];

    /** Adjustment reasons reported as write-offs (xuất hủy). */
    public const WRITE_OFF_REASONS = ['damaged', 'lost'];

    private const EXCLUDED = [OrderStatus::CANCELLED, OrderStatus::RETURNED];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Orders placed in the range, cancelled and returned orders excluded. Cost of goods is the ledger cost captured
     * when goods left the warehouse, so orders not shipped yet have no cost and are counted in missing_cost_lines.
     *
     * @return array<string, mixed>
     */
    public function sales(CarbonImmutable $from, CarbonImmutable $to, string $by = 'day'): array
    {
        $costs = DB::table('order_items')
            ->where('tenant_id', $this->tenant())
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(COALESCE(cost_snapshot, 0) * quantity) as cogs, SUM(CASE WHEN cost_snapshot IS NULL AND mapping_status <> \'service\' THEN 1 ELSE 0 END) as missing, SUM(quantity) as units');

        $base = DB::table('orders')
            ->leftJoinSub($costs, 'c', 'c.order_id', '=', 'orders.id')
            ->where('orders.tenant_id', $this->tenant())
            ->whereBetween('orders.placed_at', [$from, $to])
            ->whereNotIn('orders.status', self::EXCLUDED);

        $metrics = 'COUNT(*) as orders, COALESCE(SUM(c.units), 0) as units, COALESCE(SUM(orders.total), 0) as revenue, '
            .'COALESCE(SUM(orders.subtotal - orders.discount), 0) as goods, COALESCE(SUM(c.cogs), 0) as cogs, COALESCE(SUM(c.missing), 0) as missing';

        $grouped = match ($by) {
            'channel' => (clone $base)
                ->leftJoin('marketplace_accounts', 'marketplace_accounts.id', '=', 'orders.marketplace_account_id')
                ->leftJoin('sales_channels', 'sales_channels.id', '=', 'orders.sales_channel_id')
                ->groupBy('orders.marketplace_account_id', 'orders.sales_channel_id', 'marketplace_accounts.shop_name', 'marketplace_accounts.marketplace', 'sales_channels.name', 'sales_channels.type')
                ->selectRaw("COALESCE(marketplace_accounts.shop_name, sales_channels.name, 'Khác') as label, COALESCE(marketplace_accounts.marketplace, sales_channels.type) as kind, {$metrics}")
                ->orderByDesc('revenue')->get(),
            'staff' => (clone $base)
                ->leftJoin('users', 'users.id', '=', 'orders.created_by')
                ->groupBy('orders.created_by', 'users.name')
                ->selectRaw("COALESCE(users.name, CASE WHEN MAX(orders.marketplace_account_id) IS NULL THEN 'Không rõ' ELSE 'Đơn từ sàn' END) as label, NULL as kind, {$metrics}")
                ->orderByDesc('revenue')->get(),
            'customer' => (clone $base)
                ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
                ->groupBy('orders.customer_id', 'customers.name', 'customers.code')
                ->selectRaw("COALESCE(customers.name, 'Khách lẻ / khách sàn') as label, customers.code as kind, {$metrics}")
                ->orderByDesc('revenue')->get(),
            'warehouse' => (clone $base)
                ->leftJoin('warehouses', 'warehouses.id', '=', 'orders.warehouse_id')
                ->groupBy('orders.warehouse_id', 'warehouses.name')
                ->selectRaw("COALESCE(warehouses.name, 'Chưa gán kho') as label, NULL as kind, {$metrics}")
                ->orderByDesc('revenue')->get(),
            default => (clone $base)
                ->groupByRaw('DATE(orders.placed_at)')
                ->selectRaw("DATE(orders.placed_at) as label, NULL as kind, {$metrics}")
                ->orderBy('label')->get(),
        };

        $rows = $grouped->map(fn ($r) => $this->salesRow($r))->all();
        if ($by === 'day') {
            $rows = $this->fillDays($rows, $from, $to);
        }
        $totals = $this->salesRow((clone $base)->selectRaw("'total' as label, NULL as kind, {$metrics}")->first());
        $cancelled = DB::table('orders')->where('tenant_id', $this->tenant())->whereBetween('placed_at', [$from, $to])
            ->whereIn('status', self::EXCLUDED)->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total')->first();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'by' => $by,
            'rows' => $rows,
            'totals' => $totals,
            'excluded' => ['orders' => (int) $cancelled->n, 'revenue' => (float) $cancelled->total],
        ];
    }

    /**
     * Stock in-out-balance per SKU for the range: opening, each kind of goods in and out, closing, and the closing
     * value at the weighted average cost of the end date.
     *
     * @return array<string, mixed>
     */
    public function stockLedger(CarbonImmutable $from, CarbonImmutable $to, ?int $warehouseId = null, ?string $q = null): array
    {
        $sums = ['SUM(CASE WHEN m.created_at < ? THEN m.on_hand_delta ELSE 0 END) as opening'];
        $bindings = [$from];
        // Approved adjustments for damaged or lost goods count as write-offs, not as plain corrections.
        $writeOff = "m.type = 'ADJUSTMENT_OUT' AND m.reason IN ('".implode("','", self::WRITE_OFF_REASONS)."')";
        foreach (self::STOCK_COLUMNS as $column => $types) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $condition = match ($column) {
                'damage_out' => "(m.type IN ({$in}) OR ({$writeOff}))",
                'adjust_out' => "(m.type IN ({$in}) AND NOT ({$writeOff}))",
                default => "m.type IN ({$in})",
            };
            $sums[] = "SUM(CASE WHEN m.created_at >= ? AND {$condition} THEN m.on_hand_delta ELSE 0 END) as {$column}";
            array_push($bindings, $from, ...$types);
        }
        $sums[] = 'SUM(CASE WHEN m.created_at >= ? THEN m.on_hand_delta ELSE 0 END) as net';
        $bindings[] = $from;

        $rows = DB::table('stock_movements as m')
            ->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('m.tenant_id', $this->tenant())
            ->where('m.created_at', '<=', $to)
            ->when($warehouseId, fn ($w) => $w->where('m.warehouse_id', $warehouseId))
            ->when($q, fn ($w) => $w->where(fn ($s) => $s->where('v.sku', 'like', "%{$q}%")->orWhere('p.name', 'like', "%{$q}%")))
            ->groupBy('m.product_variant_id', 'v.ulid', 'v.sku', 'v.name', 'p.name', 'v.cost_price')
            ->selectRaw('m.product_variant_id as vid, v.ulid, v.sku, v.name as variant_name, p.name as product_name, v.cost_price, '.implode(', ', $sums), $bindings)
            ->orderBy('v.sku')
            ->limit(2000)
            ->get();
        // Closing value at the cost of the end date, not today's.
        $costs = app(CostHistory::class)->at($this->tenant(), $to, $rows->pluck('vid')->map(fn ($id) => (int) $id)->all(), $rows->pluck('cost_price', 'vid')->all());

        $out = [];
        $totals = array_fill_keys(['opening', 'in', 'out', 'closing', 'closing_value', ...array_keys(self::STOCK_COLUMNS)], 0.0);
        foreach ($rows as $r) {
            $opening = (int) $r->opening;
            $net = (int) $r->net;
            $cols = [];
            foreach (array_keys(self::STOCK_COLUMNS) as $column) {
                $cols[$column] = abs((int) $r->{$column});
            }
            $in = $cols['purchase_in'] + $cols['return_in'] + $cols['transfer_in'] + $cols['adjust_in'];
            $outQty = $cols['sale_out'] + $cols['supplier_out'] + $cols['damage_out'] + $cols['adjust_out'] + $cols['transfer_out'];
            if ($opening === 0 && $in === 0 && $outQty === 0 && $net === 0) {
                continue; // only reservations moved
            }
            $closing = $opening + $net;
            $row = [
                'variant' => ['id' => $r->ulid, 'sku' => $r->sku, 'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : ''))],
                'opening' => $opening,
                ...$cols,
                'in' => $in,
                'out' => $outQty,
                'closing' => $closing,
                'unit_cost' => round($costs[(int) $r->vid] ?? (float) $r->cost_price, 2),
                'closing_value' => round($closing * ($costs[(int) $r->vid] ?? (float) $r->cost_price), 2),
            ];
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + $row[$k];
            }
            $out[] = $row;
        }

        return ['range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'rows' => $out, 'totals' => $totals];
    }

    /**
     * Money in and out of the seller's own till and accounts: customer payments and refunds on own-channel orders,
     * payments to suppliers, and receipt and payment vouchers entered by hand. Marketplace payouts are not here (they
     * need settlement data from the marketplace).
     *
     * @return array<string, mixed>
     */
    public function cashbook(CarbonImmutable $from, CarbonImmutable $to, ?string $method = null): array
    {
        $customer = DB::table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'op.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'op.created_by')
            ->where('op.tenant_id', $this->tenant())->whereNull('op.voided_at')
            ->where('op.method', '!=', OrderPayment::EXCHANGE) // exchange set-offs move no money
            ->when($method, fn ($q) => $q->where('op.method', $method))
            ->selectRaw("op.paid_at, op.kind as type, NULL as label, op.method, CASE WHEN op.kind = 'refund' THEN -op.amount ELSE op.amount END as amount, "
                ."o.external_order_id as document, o.ulid as document_id, 'order' as document_kind, COALESCE(c.name, o.buyer_name, 'Khách lẻ') as party, op.reference, u.name as created_by");
        $supplier = DB::table('supplier_payments as sp')
            ->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'sp.purchase_order_id')
            ->leftJoin('users as u', 'u.id', '=', 'sp.created_by')
            ->where('sp.tenant_id', $this->tenant())->whereNull('sp.voided_at')
            ->when($method, fn ($q) => $q->where('sp.method', $method))
            ->selectRaw("sp.paid_at, 'supplier' as type, NULL as label, sp.method, -sp.amount as amount, po.number as document, po.ulid as document_id, 'purchase' as document_kind, s.name as party, sp.reference, u.name as created_by");

        $vouchers = DB::table('cash_vouchers as cv')
            ->join('cash_voucher_types as t', 't.id', '=', 'cv.cash_voucher_type_id')
            ->leftJoin('users as u', 'u.id', '=', 'cv.created_by')
            ->where('cv.tenant_id', $this->tenant())->whereNull('cv.voided_at')
            ->when($method, fn ($q) => $q->where('cv.method', $method))
            ->selectRaw("cv.paid_at, 'voucher' as type, t.name as label, cv.method, CASE WHEN cv.direction = 'payment' THEN -cv.amount ELSE cv.amount END as amount, "
                ."cv.number as document, cv.ulid as document_id, 'voucher' as document_kind, cv.party_name as party, cv.reference, u.name as created_by");

        $all = DB::query()->fromSub($customer->unionAll($supplier)->unionAll($vouchers), 'e');
        $opening = (float) (clone $all)->where('paid_at', '<', $from)->sum('amount');
        $entries = (clone $all)->whereBetween('paid_at', [$from, $to])->orderBy('paid_at')->limit(5000)->get();

        $balance = $opening;
        $rows = [];
        $byMethod = [];
        $in = 0.0;
        $out = 0.0;
        foreach ($entries as $e) {
            $amount = (float) $e->amount;
            $balance += $amount;
            if ($amount >= 0) {
                $in += $amount;
            } else {
                $out -= $amount;
            }
            $byMethod[$e->method] ??= ['method' => $e->method, 'in' => 0.0, 'out' => 0.0];
            $byMethod[$e->method][$amount >= 0 ? 'in' : 'out'] += abs($amount);
            $rows[] = [
                'paid_at' => CarbonImmutable::parse($e->paid_at)->toIso8601String(),
                'type' => $e->type,
                'label' => $e->label,
                'method' => $e->method,
                'amount' => $amount,
                'balance' => round($balance, 2),
                'document' => $e->document ? ['id' => $e->document_id, 'number' => $e->document, 'kind' => $e->document_kind] : null,
                'party' => $e->party,
                'reference' => $e->reference,
                'created_by' => $e->created_by,
            ];
        }

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'method' => $method,
            'opening' => round($opening, 2),
            'in' => round($in, 2),
            'out' => round($out, 2),
            'closing' => round($balance, 2),
            'by_method' => array_values($byMethod),
            'rows' => array_reverse($rows), // newest first
        ];
    }

    /** @return array<string, mixed> */
    private function salesRow(object $r): array
    {
        $goods = (float) $r->goods;
        $cogs = (float) $r->cogs;

        return [
            'label' => (string) $r->label,
            'kind' => $r->kind,
            'orders' => (int) $r->orders,
            'units' => (int) $r->units,
            'revenue' => round((float) $r->revenue, 2),
            'goods' => round($goods, 2),
            'cogs' => round($cogs, 2),
            'gross_profit' => round($goods - $cogs, 2),
            'margin' => $goods > 0 ? round(($goods - $cogs) / $goods * 100, 1) : null,
            'missing_cost_lines' => (int) $r->missing,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function fillDays(array $rows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDay = collect($rows)->keyBy('label');
        $out = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $out[] = $byDay->get($d->toDateString()) ?? ['label' => $d->toDateString(), 'kind' => null, 'orders' => 0, 'units' => 0, 'revenue' => 0.0, 'goods' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0, 'margin' => null, 'missing_cost_lines' => 0];
        }

        return $out;
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
