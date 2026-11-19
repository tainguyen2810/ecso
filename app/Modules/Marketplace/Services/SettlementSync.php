<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\FetchesSettlement;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceSettlement;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;

/**
 * Đợt 5 "Tiền thực nhận": reads each marketplace order's settlement from the marketplace's finance API into
 * marketplace_settlements (source api), where the finance page and the profit report already read them. An estimate
 * (final = false) is read again until the marketplace settles; a settlement imported from a file is never overwritten.
 */
class SettlementSync
{
    /** Orders delivered or completed within this many days are checked by the scheduled pass. */
    public const DAYS = 45;

    /** An estimate is read again at most this often (hours). */
    public const REFRESH_HOURS = 6;

    public function __construct(private readonly ConnectorRegistry $connectors) {}

    public function supports(Order $order): bool
    {
        return ! $order->isManual() && $this->connectors->has($order->marketplace) && $this->connectors->for($order->marketplace) instanceof FetchesSettlement;
    }

    /** Reads one order's settlement now. Null when the marketplace has none yet (or cannot give one). */
    public function fetch(Order $order): ?MarketplaceSettlement
    {
        $account = $order->account;
        $connector = $this->supports($order) ? $this->connectors->for($order->marketplace) : null;
        if (! $connector instanceof FetchesSettlement || ! $account instanceof MarketplaceAccount || ! $account->isActive()) {
            return null;
        }
        $existing = MarketplaceSettlement::query()->where('marketplace_account_id', $account->id)->where('external_order_id', $order->external_order_id)->first();
        if ($existing && $existing->source === 'file') {
            return $existing;
        }

        $data = $connector->settlement($account, (string) $order->external_order_id);
        if ($data === null) {
            $existing?->forceFill(['fetched_at' => now()])->save();

            return $existing;
        }
        // Shopee cannot tell: its figures are final once the order is completed (the money released then).
        $final = $data['final'] ?? $order->status === OrderStatus::COMPLETED;

        return MarketplaceSettlement::query()->updateOrCreate(
            ['marketplace_account_id' => $account->id, 'external_order_id' => $order->external_order_id],
            [
                'order_id' => $order->id,
                'order_amount' => $data['order_amount'],
                'commission_fee' => $data['commission_fee'],
                'payment_fee' => $data['payment_fee'],
                'service_fee' => $data['service_fee'],
                'fixed_fee' => $data['fixed_fee'],
                'shipping_fee' => $data['shipping_fee'],
                'net_amount' => $data['net_amount'],
                'final' => $final,
                'lines' => $data['lines'],
                'settled_at' => $data['settled_at'] ?? ($final ? ($order->completed_at ?? now()) : null),
                'source' => 'api',
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * The scheduled pass for one shop: delivered and completed orders of the last DAYS days without a settled figure,
     * oldest read first, at most $limit per pass.
     *
     * @return array{read: int, found: int, failed: int}
     */
    public function syncAccount(MarketplaceAccount $account, int $limit = 100): array
    {
        $counts = ['read' => 0, 'found' => 0, 'failed' => 0];
        $orders = Order::query()->with('account')->where('marketplace_account_id', $account->id)
            ->whereIn('status', [OrderStatus::DELIVERED, OrderStatus::COMPLETED])
            ->where('placed_at', '>=', now()->subDays(self::DAYS))
            ->whereNotExists(fn ($q) => $q->from('marketplace_settlements as s')->whereColumn('s.marketplace_account_id', 'orders.marketplace_account_id')
                ->whereColumn('s.external_order_id', 'orders.external_order_id')
                ->where(fn ($w) => $w->where('s.final', true)->orWhere('s.fetched_at', '>', now()->subHours(self::REFRESH_HOURS))))
            ->orderBy('placed_at')->limit($limit)->get();

        foreach ($orders as $order) {
            $counts['read']++;
            try {
                $counts['found'] += (int) ($this->fetch($order) !== null);
            } catch (ConnectorException $e) {
                $counts['failed']++;
                if ($e->authExpired) {
                    break;
                }
            }
        }

        return $counts;
    }

    /**
     * The "Tiền thực nhận" card of the order page: the settlement and, for members who may see cost and profit, the
     * cost of goods (captured at shipping, the current cost before) and the profit after the marketplace's fees.
     *
     * @return array{supported: bool, settlement: array<string, mixed>|null, cogs: string|null, cogs_estimated: bool, profit: string|null, profit_hidden: bool}
     */
    public function view(Order $order, bool $withProfit): array
    {
        $s = $order->isManual() ? null : MarketplaceSettlement::query()->where('marketplace_account_id', $order->marketplace_account_id)
            ->where('external_order_id', $order->external_order_id)->first();
        $cogs = null;
        $estimated = false;
        if ($withProfit && $order->status !== OrderStatus::CANCELLED) {
            $order->loadMissing('items.variant');
            $cogs = 0.0;
            foreach ($order->items as $item) {
                $unit = $item->cost_snapshot !== null ? (float) $item->cost_snapshot : (float) ($item->variant->cost_price ?? 0);
                if ($item->cost_snapshot === null) {
                    $estimated = true;
                    if ($unit <= 0) {
                        $cogs = null;
                        break;
                    }
                }
                $cogs += $unit * (int) $item->quantity;
            }
        }
        $fees = $s ? (float) $s->commission_fee + (float) $s->payment_fee + (float) $s->service_fee + (float) $s->fixed_fee + (float) $s->shipping_fee : null;
        $money = fn (?float $v) => $v === null ? null : number_format($v, 2, '.', '');

        return [
            'supported' => $this->supports($order),
            'settlement' => $s ? [
                'final' => (bool) $s->final,
                'source' => $s->source,
                'settled_at' => $s->settled_at?->toIso8601String(),
                'fetched_at' => $s->fetched_at?->toIso8601String(),
                'order_amount' => $s->order_amount,
                'fees' => $money($fees),
                'net_amount' => $s->net_amount,
                'lines' => array_map(fn ($l) => ['label' => (string) $l['label'], 'amount' => $money((float) $l['amount'])], $s->lines ?? []),
            ] : null,
            'cogs' => $money($cogs),
            'cogs_estimated' => $cogs !== null && $estimated,
            'profit' => $s && $cogs !== null ? $money((float) $s->net_amount - $cogs) : null,
            'profit_hidden' => ! $withProfit,
        ];
    }
}
