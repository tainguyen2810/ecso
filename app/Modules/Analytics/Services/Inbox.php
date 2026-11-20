<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Inventory\Services\LotLedger;
use App\Modules\Inventory\Services\LowStockQuery;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Services\SyncDashboard;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderQuery;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierAllocation;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * The bell in the top bar: what is waiting for someone right now, grouped by kind (never one row per order). Each
 * item is computed live, so it disappears once the work is done. A member only gets the kinds their permissions
 * cover; order counts follow the same visibility as the order list.
 */
class Inbox
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LowStockQuery $lowStock,
        private readonly SupplierAllocation $allocation,
        private readonly LotLedger $lots,
    ) {}

    /**
     * @return list<array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string}>
     */
    public function items(): array
    {
        $items = [];
        if ($this->context->can('orders.view')) {
            array_push($items, ...$this->orders());
        }
        if ($this->context->can('sync.view')) {
            array_push($items, ...$this->sync());
        }
        if ($this->context->can('inventory.view')) {
            array_push($items, ...$this->stock());
        }
        if ($this->context->can('finance.profit')) {
            array_push($items, ...$this->losses());
        }
        if ($this->context->can('finance.approve')) {
            array_push($items, ...$this->vouchers());
        }
        if ($this->context->can('purchasing.payment') || $this->context->can('finance.view')) {
            array_push($items, ...$this->supplierDebts());
        }

        return array_values(array_filter($items, fn (array $i) => $i['count'] > 0));
    }

    /** @return list<array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string}> */
    private function orders(): array
    {
        $open = fn () => OrderQuery::visible(Order::query())->whereIn('status', OrderStatus::OPEN);
        $failed = OrderQuery::visible(Order::query())->where('status', OrderStatus::FAILED_DELIVERY)->count();
        $overdue = $open()->where('ship_by_at', '<', now())->count();
        $hours = (int) config('ecso.orders.due_soon_hours');
        $dueSoon = $open()->whereBetween('ship_by_at', [now(), now()->addHours($hours)])->count();
        $toConfirm = OrderQuery::visible(Order::query())->where('status', OrderStatus::PENDING_CONFIRMATION)->count();
        $toPack = OrderQuery::visible(Order::query())->where('status', OrderStatus::READY_TO_PACK)->count();
        $shortage = $open()->where('has_shortage', true)->count();
        $awaiting = $open()->where('awaiting_purchase', true)->count();

        return [
            $this->item('orders.failed_delivery', 'orders', 'red', $failed, "{$failed} đơn giao thất bại", 'Cần giao lại hoặc nhận hàng hoàn', '/orders?status=FAILED_DELIVERY'),
            $this->item('orders.overdue', 'orders', 'red', $overdue, "{$overdue} đơn quá hạn giao", 'Đã quá hạn giao cho đơn vị vận chuyển', '/orders?flags=overdue'),
            $this->item('orders.due_soon', 'orders', 'amber', $dueSoon, "{$dueSoon} đơn sắp quá hạn giao", "Hạn giao trong {$hours} giờ tới", '/orders?flags=due_soon'),
            $this->item('orders.shortage', 'orders', 'amber', $shortage, "{$shortage} đơn thiếu hàng", 'Kho không đủ hàng để giữ cho đơn', '/orders?flags=shortage'),
            $this->item('orders.awaiting_purchase', 'orders', 'blue', $awaiting, "{$awaiting} đơn chờ nhập hàng", 'Hàng đặt: tạo đơn mua để nhập về giao khách', '/orders?flags=awaiting_purchase'),
            $this->item('orders.to_confirm', 'orders', 'green', $toConfirm, "{$toConfirm} đơn chờ xác nhận", null, '/orders?tab=to_confirm'),
            $this->item('orders.to_pack', 'orders', 'green', $toPack, "{$toPack} đơn chờ đóng gói", null, '/orders?tab=to_pack'),
        ];
    }

    /** @return list<array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string}> */
    private function sync(): array
    {
        $accounts = MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)->get(['id', 'status', 'shop_name']);
        if ($accounts->isEmpty()) {
            return [];
        }
        $ids = $accounts->pluck('id');
        $lost = $accounts->whereIn('status', [MarketplaceAccount::EXPIRED, MarketplaceAccount::ERROR]);
        $expiring = MarketplaceCredential::query()->whereIn('marketplace_account_id', $ids)->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<', now()->addDays(SyncDashboard::TOKEN_WARN_DAYS))->count();
        $failedPush = InventorySyncOutbox::query()->whereIn('marketplace_account_id', $ids)->where('status', InventorySyncOutbox::FAILED)->count();
        $openErrors = SyncError::query()->whereIn('marketplace_account_id', $ids)->whereNull('resolved_at')->count();
        $problems = $failedPush + $openErrors;

        return [
            $this->item('sync.disconnected', 'ops', 'red', $lost->count(), "{$lost->count()} shop mất kết nối", $lost->pluck('shop_name')->take(2)->implode(', ') ?: null, '/marketplaces'),
            $this->item('sync.problems', 'ops', 'red', $problems, "{$problems} lỗi đồng bộ cần xử lý", 'Tác vụ hết lượt thử hoặc bị sàn từ chối', '/marketplaces/sync?tab=problems'),
            $this->item('sync.token_expiring', 'ops', 'amber', $expiring, "{$expiring} kết nối sàn sắp hết hạn", 'Cần ủy quyền lại trong '.SyncDashboard::TOKEN_WARN_DAYS.' ngày tới', '/marketplaces'),
        ];
    }

    /** @return list<array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string}> */
    private function stock(): array
    {
        $low = $this->lowStock->forTenant($this->context->id(), 200);
        $first = $low[0] ?? null;
        $count = count($low);

        $lots = $this->lots->alertCounts();
        $report = $this->context->can('reports.view') ? '/reports/view/expiry' : '/inventory';
        $soonest = $lots['first'] ? "Sớm nhất: {$lots['first']['name']} lô {$lots['first']['lot_no']} HSD {$lots['first']['expiry_date']}" : null;

        return [
            $this->item('stock.low', 'ops', 'amber', $count, ($count >= 200 ? '200+' : $count).' SKU dưới tồn an toàn',
                $first ? "{$first['warehouse']} · {$first['name']} còn {$first['available']}" : null,
                $this->context->can('purchasing.view') ? '/purchasing/reorder' : '/inventory'),
            $this->item('stock.expired', 'ops', 'red', $lots['blocked'], "{$lots['blocked']} lô hết hạn / ngừng bán còn tồn", 'Chuyển sang kho hàng lỗi hoặc xuất hủy', $report.($report === '/inventory' ? '' : '?by=expired')),
            $this->item('stock.expiring', 'ops', 'amber', $lots['expiring'], "{$lots['expiring']} lô sắp hết hạn", $soonest, $report),
        ];
    }

    /**
     * Đợt 14: orders of the last 7 days that sell at a loss after marketplace fees.
     *
     * @return list<array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string}>
     */
    private function losses(): array
    {
        $totals = app(LossReports::class)->orders(now()->subDays(7), now())['totals'];
        $count = $totals['orders'];

        return [$this->item('orders.loss', 'money', 'red', $count, "{$count} đơn lỗ sau phí sàn", 'Tổng lỗ '.self::money((string) abs($totals['profit'])).' trong 7 ngày', '/reports/view/loss-orders')];
    }

    /** @return list<array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string}> */
    private function vouchers(): array
    {
        $pending = CashVoucher::query()->where('status', CashVoucher::PENDING);
        $count = (clone $pending)->count();
        $amount = $count > 0 ? (string) (clone $pending)->sum('amount') : '0';

        return [$this->item('cash.pending', 'money', 'blue', $count, "{$count} phiếu chi chờ duyệt", 'Tổng '.self::money($amount), '/cash?status=pending')];
    }

    /** @return list<array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string}> */
    private function supplierDebts(): array
    {
        $owed = Supplier::query()->withBalance()->having('debt', '>', 0)->get()->map(fn (Supplier $s) => (int) $s->id)->values()->all();
        $overdue = array_filter($this->allocation->forSuppliers($owed), fn (array $a) => $a['overdue'] > 0);
        $amount = array_sum(array_column($overdue, 'overdue'));
        $count = count($overdue);

        return [$this->item('suppliers.overdue', 'money', 'amber', $count, "Nợ {$count} nhà cung cấp quá hạn", 'Tổng '.self::money((string) $amount),
            $this->context->can('reports.view') ? '/reports/view/payables' : '/purchasing/suppliers')];
    }

    /** @return array{key: string, group: string, tone: string, count: int, title: string, detail: string|null, to: string} */
    private function item(string $key, string $group, string $tone, int $count, string $title, ?string $detail, string $to): array
    {
        return ['key' => $key, 'group' => $group, 'tone' => $tone, 'count' => $count, 'title' => $title, 'detail' => $detail, 'to' => $to];
    }

    private static function money(string $amount): string
    {
        return number_format((float) $amount, 0, ',', '.').' ₫';
    }
}
