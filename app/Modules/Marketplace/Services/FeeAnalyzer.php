<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Models\ChannelPriceRule;
use App\Modules\Marketplace\Models\FeeAlert;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceSettlement;
use App\Modules\Orders\Domain\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The fees a marketplace actually charged, from settlement data: per shop and per product category (an order's fees
 * are split over its lines by line amount), and the daily check that compares them with the fee table.
 */
class FeeAnalyzer
{
    public function __construct(private readonly MarketplacePricing $pricing, private readonly MarketplacePriceBoard $board) {}

    /**
     * @return array{shop: array<string, float|int>|null, categories: array<int, array<string, float|int>>}
     */
    public function actual(MarketplaceAccount $account, ?int $days = null): array
    {
        $days ??= (int) config('marketplaces.pricing.check_days');
        $settlements = MarketplaceSettlement::query()->where('marketplace_account_id', $account->id)
            ->where('settled_at', '>=', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereNotIn('order_id', DB::table('orders')
                ->where('tenant_id', $account->tenant_id)->whereIn('status', [OrderStatus::CANCELLED, OrderStatus::RETURNED])->select('id')))
            ->get();
        if ($settlements->isEmpty()) {
            return ['shop' => null, 'categories' => []];
        }

        // Category share of each linked order, by line amount.
        $lines = DB::table('order_items')->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('order_items.order_id', $settlements->pluck('order_id')->filter()->unique())
            ->whereNotNull('products.category_id')
            ->selectRaw('order_items.order_id, products.category_id, SUM(order_items.unit_price * order_items.quantity - order_items.discount) as amount')
            ->groupBy('order_items.order_id', 'products.category_id')->get()->groupBy('order_id');

        $buckets = ['shop' => null];
        foreach ($settlements as $s) {
            $this->add($buckets['shop'], $s, 1.0);
            $parts = $lines->get($s->order_id) ?? collect();
            $total = (float) $parts->sum('amount');
            foreach ($parts as $part) {
                if ($total > 0) {
                    $this->add($buckets[(int) $part->category_id], $s, (float) $part->amount / $total);
                }
            }
        }

        $categories = [];
        foreach ($buckets as $key => $sums) {
            if ($key !== 'shop') {
                $categories[(int) $key] = $this->stats($sums);
            }
        }

        return ['shop' => $this->stats($buckets['shop']), 'categories' => $categories];
    }

    /** @param  array<string, float>|null  $sums */
    private function add(?array &$sums, MarketplaceSettlement $s, float $share): void
    {
        $sums ??= ['orders' => 0, 'order_amount' => 0, 'commission_fee' => 0, 'payment_fee' => 0, 'service_fee' => 0, 'fixed_fee' => 0, 'shipping_fee' => 0, 'net_amount' => 0];
        $sums['orders'] += $share;
        foreach (MarketplaceSettlement::AMOUNTS as $k) {
            $sums[$k] += (float) $s->getAttribute($k) * $share;
        }
    }

    /**
     * @param  array<string, float>  $sums
     * @return array<string, float|int>
     */
    private function stats(array $sums): array
    {
        $amount = $sums['order_amount'] ?: 1;
        $pct = fn (string $k) => round($sums[$k] / $amount * 100, 2);
        $stats = [
            'orders' => (int) round($sums['orders']),
            'order_amount' => round($sums['order_amount']),
            'commission_pct' => $pct('commission_fee'),
            'payment_pct' => $pct('payment_fee'),
            'service_pct' => $pct('service_fee'),
            'fixed_fee' => round($sums['fixed_fee']),
            'fixed_fee_per_order' => $sums['orders'] > 0 ? round($sums['fixed_fee'] / $sums['orders']) : 0,
            'shipping_fee' => round($sums['shipping_fee']),
            'net_amount' => round($sums['net_amount']),
        ];
        $stats['total_pct'] = round($stats['commission_pct'] + $stats['payment_pct'] + $stats['service_pct'], 2);

        return $stats;
    }

    /**
     * Orders sold below cost after fees in the last days (cost = cost of goods at the time of sale).
     *
     * @return list<array{order_id: string, external_order_id: string, net: float, cost: float, loss: float, skus: list<string>}>
     */
    public function lossOrders(MarketplaceAccount $account, ?int $days = null): array
    {
        $days ??= (int) config('marketplaces.pricing.loss_days');
        $rows = MarketplaceSettlement::query()->where('marketplace_account_id', $account->id)->whereNotNull('order_id')
            ->where('settled_at', '>=', now()->subDays($days))->get();
        $costs = DB::table('order_items')->whereIn('order_id', $rows->pluck('order_id'))->whereNotNull('cost_snapshot')
            ->selectRaw('order_id, SUM(cost_snapshot * quantity) as cost, GROUP_CONCAT(DISTINCT seller_sku) as skus')->groupBy('order_id')->get()->keyBy('order_id');
        $orders = DB::table('orders')->whereIn('id', $rows->pluck('order_id'))->pluck('ulid', 'id');

        $out = [];
        foreach ($rows as $s) {
            $cost = (float) ($costs->get($s->order_id)->cost ?? 0);
            $net = (float) $s->net_amount + (float) $s->shipping_fee; // shipping is charged on top of the goods, not part of their price
            if ($cost > 0 && $net < $cost) {
                $out[] = [
                    'order_id' => (string) ($orders[$s->order_id] ?? ''),
                    'external_order_id' => $s->external_order_id,
                    'net' => round($net),
                    'cost' => round($cost),
                    'loss' => round($cost - $net),
                    'skus' => array_values(array_filter(explode(',', (string) ($costs->get($s->order_id)->skus ?? '')))),
                ];
            }
        }

        return $out;
    }

    /**
     * Writes the actual percentages into a fee table (shop rule: every fee; category rule: commission only, the
     * other fees are the shop's).
     *
     * @param  array<string, float|int>  $stats
     */
    public function useActual(ChannelPriceRule $rule, array $stats, int $days): ChannelPriceRule
    {
        $fields = $rule->category_id === null
            ? ['commission_pct' => $stats['commission_pct'], 'payment_pct' => $stats['payment_pct'], 'service_pct' => $stats['service_pct'], 'fixed_fee' => $stats['fixed_fee_per_order']]
            : ['commission_pct' => $stats['commission_pct']];
        $rule->forceFill($fields + ['suggested_from' => ['days' => $days, 'orders' => $stats['orders'], 'at' => now()->toIso8601String()]])->save();

        return $rule;
    }

    /**
     * The daily check of one shop. Returns the alerts it opened (not the ones already known).
     *
     * @return list<FeeAlert>
     */
    public function check(MarketplaceAccount $account): array
    {
        $rules = $this->pricing->rulesOf($account);
        $shopRule = $rules->get('');
        if (! $shopRule) {
            return [];
        }
        $days = (int) config('marketplaces.pricing.check_days');
        $actual = $this->actual($account, $days);
        $opened = [];

        if ($actual['shop'] && $actual['shop']['orders'] >= (int) config('marketplaces.pricing.min_orders')) {
            $fee = $this->pricing->effective($rules, null);
            $drift = round($actual['shop']['total_pct'] - $fee['total_pct'], 2);
            if (abs($drift) >= (float) config('marketplaces.pricing.drift_points')) {
                $components = [];
                foreach (['commission_pct', 'payment_pct', 'service_pct'] as $k) {
                    if (abs($actual['shop'][$k] - $fee[$k]) >= 0.1) {
                        $components[$k] = ['actual' => $actual['shop'][$k], 'table' => $fee[$k]];
                    }
                }
                $data = ['actual' => $actual['shop'], 'table_total' => $fee['total_pct'], 'drift' => $drift, 'components' => $components, 'days' => $days];
                $fingerprint = sprintf('drift:%d:%s:%s', $account->id, number_format($actual['shop']['total_pct'], 1), number_format($fee['total_pct'], 1));

                if ($shopRule->auto_update_fees) {
                    $this->useActual($shopRule, $actual['shop'], $days);
                    if ($shopRule->auto_apply) {
                        $this->board->apply(null, $account->id);
                    }
                    $alert = $this->open($account, FeeAlert::FEE_DRIFT, $fingerprint, $data + ['auto_updated' => true], 'applied');
                } else {
                    $alert = $this->open($account, FeeAlert::FEE_DRIFT, $fingerprint, $data);
                }
                if ($alert) {
                    $opened[] = $alert;
                }
            }
        }

        $losses = $this->lossOrders($account);
        if ($losses !== []) {
            $ids = array_column($losses, 'external_order_id');
            sort($ids);
            $alert = $this->open($account, FeeAlert::LOSS_ORDERS, sprintf('loss:%d:%s', $account->id, md5(implode(',', $ids))), [
                'orders' => array_slice($losses, 0, 50),
                'count' => count($losses),
                'total_loss' => array_sum(array_column($losses, 'loss')),
                'days' => (int) config('marketplaces.pricing.loss_days'),
            ]);
            if ($alert) {
                // A newer list of loss orders replaces the older one.
                FeeAlert::query()->where('marketplace_account_id', $account->id)->where('type', FeeAlert::LOSS_ORDERS)
                    ->where('status', FeeAlert::OPEN)->whereKeyNot($alert->id)->update(['status' => 'dismissed', 'resolved_at' => now()]);
                $opened[] = $alert;
            }
        }

        return $opened;
    }

    /** @param  array<string, mixed>  $data */
    private function open(MarketplaceAccount $account, string $type, string $fingerprint, array $data, string $status = FeeAlert::OPEN): ?FeeAlert
    {
        if (FeeAlert::query()->where('fingerprint', $fingerprint)->exists()) {
            return null; // already raised (and maybe dismissed): not raised again until the figures change
        }
        if ($type === FeeAlert::FEE_DRIFT) {
            FeeAlert::query()->where('marketplace_account_id', $account->id)->where('type', $type)->where('status', FeeAlert::OPEN)
                ->update(['status' => 'dismissed', 'resolved_at' => now()]);
        }

        return FeeAlert::query()->create([
            'marketplace_account_id' => $account->id,
            'type' => $type,
            'fingerprint' => $fingerprint,
            'data' => $data,
            'status' => $status,
            'resolved_at' => $status === FeeAlert::OPEN ? null : now(),
        ]);
    }

    /** "Dùng số thực tế và tính lại giá" on a fee-drift alert. */
    public function applyAlert(FeeAlert $alert): FeeAlert
    {
        abort_unless($alert->type === FeeAlert::FEE_DRIFT && $alert->status === FeeAlert::OPEN && $alert->account, 422, 'Cảnh báo này đã được xử lý.');
        $rule = $this->pricing->rulesOf($alert->account)->get('');
        abort_unless($rule !== null, 422, 'Gian hàng chưa có bảng phí.');
        $this->useActual($rule, $alert->data['actual'], (int) ($alert->data['days'] ?? 30));
        if ($rule->auto_apply) {
            $this->board->apply(null, $alert->marketplace_account_id);
        }
        $alert->forceFill(['status' => 'applied', 'resolved_at' => now(), 'resolved_by' => Auth::id()])->save();

        return $alert;
    }

    public function dismiss(FeeAlert $alert): FeeAlert
    {
        $alert->forceFill(['status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => Auth::id()])->save();

        return $alert;
    }

    /** @return Collection<int, FeeAlert> */
    public function openAlerts(): Collection
    {
        return FeeAlert::query()->with('account')->where('status', FeeAlert::OPEN)->latest('id')->get();
    }
}
