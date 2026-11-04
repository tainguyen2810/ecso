<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Models\ChannelPriceRule;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Support\Collection;

/**
 * Marketplace price from fees. The marketplace takes a percentage of the selling price itself, so the price is
 * divided, not added to, so that what is left after fees is the base price:
 *
 *   price = (base + fixed fee) ÷ (1 − total % fees), rounded up
 *
 * and never below the price that keeps the minimum margin on cost:  (cost × (1 + m) + fixed fee) ÷ (1 − total %).
 */
class MarketplacePricing
{
    /** Above this total, a price cannot be computed sensibly. */
    public const MAX_TOTAL_PCT = 90;

    /**
     * Shop rule and category rules of a shop, keyed by category id ('' = shop).
     *
     * @return Collection<string, ChannelPriceRule>
     */
    public function rulesOf(MarketplaceAccount $account): Collection
    {
        return ChannelPriceRule::query()->where('marketplace_account_id', $account->id)->get()
            ->keyBy(fn (ChannelPriceRule $r) => (string) ($r->category_id ?? ''));
    }

    /**
     * Fee table that applies to a SKU of a category on a shop: the category rule's fields over the shop rule's.
     *
     * @param  Collection<string, ChannelPriceRule>  $rules
     * @return array{commission_pct: float, payment_pct: float, service_pct: float, fixed_fee: float, total_pct: float, rounding: int, min_margin_pct: float, auto_apply: bool}|null
     */
    public function effective(Collection $rules, ?int $categoryId): ?array
    {
        $shop = $rules->get('');
        if (! $shop) {
            return null;
        }
        $category = $categoryId ? $rules->get((string) $categoryId) : null;
        $pick = fn (string $f) => (float) ($category?->getAttribute($f) ?? $shop->getAttribute($f) ?? 0);

        $fees = [
            'commission_pct' => $pick('commission_pct'),
            'payment_pct' => $pick('payment_pct'),
            'service_pct' => $pick('service_pct'),
            'fixed_fee' => $pick('fixed_fee'),
        ];

        return $fees + [
            'total_pct' => round($fees['commission_pct'] + $fees['payment_pct'] + $fees['service_pct'], 2),
            'rounding' => max(1, (int) $shop->rounding),
            'min_margin_pct' => (float) $shop->min_margin_pct,
            'auto_apply' => (bool) $shop->auto_apply,
        ];
    }

    /**
     * @param  array{total_pct: float, fixed_fee: float, rounding: int, min_margin_pct: float}  $fee
     * @return array{raw: float, price: float, fees: float, net: float, profit: float|null, margin_pct: float|null, raised: bool}|null null when the fees are 90 % or more
     */
    public static function quote(float $base, ?float $cost, array $fee): ?array
    {
        $r = $fee['total_pct'] / 100;
        if ($fee['total_pct'] >= self::MAX_TOTAL_PCT) {
            return null;
        }
        $raw = ($base + $fee['fixed_fee']) / (1 - $r);
        $price = self::roundUp($raw, $fee['rounding']);
        $raised = false;

        if ($cost !== null && $cost > 0) {
            $floor = self::roundUp(($cost * (1 + $fee['min_margin_pct'] / 100) + $fee['fixed_fee']) / (1 - $r), $fee['rounding']);
            if ($floor > $price) {
                [$price, $raised] = [$floor, true];
            }
        }

        return ['raw' => round($raw, 2), 'price' => $price, 'raised' => $raised] + self::outcome($price, $cost, $fee);
    }

    /**
     * What the shop keeps from a price after fees, and the profit on cost.
     *
     * @param  array{total_pct: float, fixed_fee: float}  $fee
     * @return array{fees: float, net: float, profit: float|null, margin_pct: float|null}
     */
    public static function outcome(float $price, ?float $cost, array $fee): array
    {
        $fees = round($price * $fee['total_pct'] / 100 + $fee['fixed_fee'], 2);
        $net = round($price - $fees, 2);
        $profit = $cost !== null && $cost > 0 ? round($net - $cost, 2) : null;

        return ['fees' => $fees, 'net' => $net, 'profit' => $profit, 'margin_pct' => $profit !== null ? round($profit / $cost * 100, 1) : null];
    }

    /**
     * Is a price below the minimum margin (or a loss) after fees?
     *
     * @param  array{total_pct: float, fixed_fee: float, min_margin_pct: float}  $fee
     */
    public static function belowMargin(float $price, ?float $cost, array $fee): bool
    {
        if ($cost === null || $cost <= 0 || $price <= 0) {
            return false;
        }

        return self::outcome($price, $cost, $fee)['net'] < $cost * (1 + $fee['min_margin_pct'] / 100) - 0.5;
    }

    public static function roundUp(float $value, int $step): float
    {
        return (float) (ceil(round($value / $step, 6)) * $step);
    }
}
