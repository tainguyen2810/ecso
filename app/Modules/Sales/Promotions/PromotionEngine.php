<?php

namespace App\Modules\Sales\Promotions;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sales\Models\Promotion;
use App\Modules\Sales\Models\PromotionUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out what an order of the shop's own channels receives (C4):
 * - the one automatic money-off promotion (order or product) worth the most to the buyer,
 * - every automatic gift promotion it qualifies for,
 * - plus the promotion of the code typed, if any (a code never stacks with another code).
 * Gift lines and earlier promotion lines are left out of the goods counted.
 */
class PromotionEngine
{
    /**
     * @param  array<int, array{variant_id: int|null, category_id: int|null, quantity: int, unit_price: string, discount: string}>  $lines
     * @param  array{channel_id: int|null, customer_id: int|null, group_id: int|null, order_id?: int|null, code?: string|null, auto?: bool, at?: Carbon|null}  $context
     */
    public function evaluate(array $lines, array $context): PromotionResult
    {
        $at = $context['at'] ?? now();
        $code = strtoupper(trim((string) ($context['code'] ?? '')));
        $result = new PromotionResult;

        $candidates = Promotion::query()->where('status', Promotion::ACTIVE)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->where(fn ($q) => $q->whereNull('code')->when($code !== '', fn ($q) => $q->orWhere('code', $code)))
            ->get()
            ->filter(fn (Promotion $p) => $this->fits($p, $context));
        $used = $this->usage($candidates, $context);

        $offers = [];
        foreach ($candidates as $p) {
            if ($p->usage_limit !== null && ($used['total'][$p->id] ?? 0) >= $p->usage_limit) {
                if ($p->code !== null) {
                    $result->codeError = "Mã {$p->code} đã hết lượt dùng.";
                }

                continue;
            }
            if ($p->per_customer_limit !== null) {
                if ($context['customer_id'] === null) {
                    if ($p->code !== null) {
                        $result->codeError = "Mã {$p->code} chỉ dùng cho khách có tên trong danh sách. Chọn khách hàng trước.";
                    }

                    continue;
                }
                if (($used['customer'][$p->id] ?? 0) >= $p->per_customer_limit) {
                    if ($p->code !== null) {
                        $result->codeError = "Khách này đã dùng mã {$p->code}.";
                    }

                    continue;
                }
            }
            $offer = $this->offer($p, $lines);
            if ($offer === null) {
                if ($p->code !== null) {
                    $result->codeError = $this->whyNot($p, $lines);
                }

                continue;
            }
            $offers[] = $offer;
        }

        $byCode = collect($offers)->first(fn (array $o) => $o['promotion']->code !== null);
        $auto = collect($offers)->filter(fn (array $o) => $o['promotion']->code === null);
        if ($context['auto'] ?? true) {
            $best = $auto->filter(fn (array $o) => $o['promotion']->type !== Promotion::GIFT)->sortByDesc(fn (array $o) => (float) $o['amount'])->first();
            if ($best !== null) {
                $result->add($best);
            }
            foreach ($auto->filter(fn (array $o) => $o['promotion']->type === Promotion::GIFT) as $gift) {
                $result->add($gift);
            }
        }
        if ($byCode !== null) {
            $result->add($byCode);
        } elseif ($code !== '' && $result->codeError === null) {
            $result->codeError = "Mã {$code} không đúng hoặc không dùng được cho đơn này.";
        }
        // Money off never goes past the goods.
        $goods = $this->goods($lines);
        if (bccomp($result->discount, $goods, 2) > 0) {
            $result->discount = $goods;
        }

        return $result;
    }

    /**
     * @param  array{channel_id: int|null, group_id: int|null}  $context
     */
    private function fits(Promotion $p, array $context): bool
    {
        if (! empty($p->channel_ids) && ! in_array((int) $context['channel_id'], array_map('intval', $p->channel_ids), true)) {
            return false;
        }

        return empty($p->customer_group_ids) || in_array((int) $context['group_id'], array_map('intval', $p->customer_group_ids), true);
    }

    /**
     * Earlier uses of each promotion, on other orders that were not cancelled.
     *
     * @param  Collection<int, Promotion>  $promotions
     * @return array{total: array<int, int>, customer: array<int, int>}
     */
    private function usage(Collection $promotions, array $context): array
    {
        $limited = $promotions->filter(fn (Promotion $p) => $p->usage_limit !== null || $p->per_customer_limit !== null)->pluck('id');
        if ($limited->isEmpty()) {
            return ['total' => [], 'customer' => []];
        }
        $base = PromotionUsage::query()->whereIn('promotion_usages.promotion_id', $limited)
            ->join('orders', 'orders.id', '=', 'promotion_usages.order_id')
            ->where('orders.status', '!=', 'CANCELLED')
            ->when($context['order_id'] ?? null, fn ($q, $id) => $q->where('promotion_usages.order_id', '!=', $id));

        return [
            'total' => (clone $base)->groupBy('promotion_usages.promotion_id')->select('promotion_usages.promotion_id', DB::raw('COUNT(*) as n'))->pluck('n', 'promotion_id')->map(fn ($n) => (int) $n)->all(),
            'customer' => $context['customer_id'] === null ? [] : (clone $base)->where('promotion_usages.customer_id', $context['customer_id'])
                ->groupBy('promotion_usages.promotion_id')->select('promotion_usages.promotion_id', DB::raw('COUNT(*) as n'))->pluck('n', 'promotion_id')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /**
     * What the promotion gives this order, or null when it does not qualify.
     *
     * @return array{promotion: Promotion, amount: string, gifts: array<int, array{variant_id: int, quantity: int}>}|null
     */
    private function offer(Promotion $p, array $lines): ?array
    {
        $scoped = array_values(array_filter($lines, fn (array $l) => $this->inScope($p, $l)));
        $goods = $this->goods($scoped);
        if (bccomp($goods, '0', 2) <= 0 || bccomp($this->goods($lines), (string) $p->min_subtotal, 2) < 0) {
            return null;
        }

        if ($p->type === Promotion::GIFT) {
            $qty = array_sum(array_column($scoped, 'quantity'));
            $times = $p->buy_qty > 0 ? intdiv($qty, $p->buy_qty) : 0;
            if ($times < 1 || $p->gift_variant_id === null) {
                return null;
            }
            $quantity = $times * max(1, (int) $p->gift_qty);
            $price = (string) (ProductVariant::query()->whereKey($p->gift_variant_id)->value('ref_price') ?? '0');

            return ['promotion' => $p, 'amount' => bcmul($price, (string) $quantity, 2), 'gifts' => [['variant_id' => (int) $p->gift_variant_id, 'quantity' => $quantity]]];
        }

        if ($p->type === Promotion::PRODUCT) {
            $off = '0';
            foreach ($scoped as $l) {
                $lineGoods = $this->goods([$l]);
                $cut = match ($p->discount_kind) {
                    'percent' => bcdiv(bcmul($lineGoods, (string) $p->discount_value, 4), '100', 2),
                    'amount' => bcmul((string) $p->discount_value, (string) $l['quantity'], 2),
                    default => bcmul($this->positive(bcsub((string) $l['unit_price'], (string) $p->discount_value, 2)), (string) $l['quantity'], 2),
                };
                $off = bcadd($off, $this->min($cut, $lineGoods), 2);
            }
        } else {
            $value = (string) $p->discount_value;
            foreach ($p->tiers ?? [] as $tier) {
                if (bccomp($this->goods($lines), (string) $tier['min_subtotal'], 2) >= 0 && bccomp((string) $tier['value'], $value, 2) > 0) {
                    $value = (string) $tier['value'];
                }
            }
            $off = $p->discount_kind === 'percent' ? bcdiv(bcmul($goods, $value, 4), '100', 2) : $this->min($value, $goods);
        }
        if ($p->max_discount !== null && bccomp((string) $p->max_discount, '0', 2) > 0) {
            $off = $this->min($off, (string) $p->max_discount);
        }
        $off = number_format(round((float) $off), 2, '.', ''); // to the đồng

        return bccomp($off, '0', 2) > 0 ? ['promotion' => $p, 'amount' => $off, 'gifts' => []] : null;
    }

    private function whyNot(Promotion $p, array $lines): string
    {
        if (bccomp($this->goods($lines), (string) $p->min_subtotal, 2) < 0) {
            return "Mã {$p->code} cần đơn từ ".number_format((float) $p->min_subtotal, 0, ',', '.').' ₫.';
        }
        if ($p->type === Promotion::GIFT) {
            return "Mã {$p->code} cần mua đủ {$p->buy_qty} sản phẩm áp dụng.";
        }

        return "Mã {$p->code} không áp dụng cho sản phẩm trong đơn.";
    }

    /** @param  array{variant_id: int|null, category_id: int|null}  $line */
    private function inScope(Promotion $p, array $line): bool
    {
        if ($line['variant_id'] === null) {
            return false; // service lines never take promotions
        }
        if (empty($p->variant_ids) && empty($p->category_ids)) {
            return true;
        }

        return in_array($line['variant_id'], array_map('intval', $p->variant_ids ?? []), true)
            || ($line['category_id'] !== null && in_array($line['category_id'], array_map('intval', $p->category_ids ?? []), true));
    }

    /** Goods after line discounts. */
    private function goods(array $lines): string
    {
        $sum = '0';
        foreach ($lines as $l) {
            $sum = bcadd($sum, $this->positive(bcsub(bcmul((string) $l['unit_price'], (string) $l['quantity'], 2), (string) $l['discount'], 2)), 2);
        }

        return $sum;
    }

    private function positive(string $v): string
    {
        return bccomp($v, '0', 2) < 0 ? '0.00' : $v;
    }

    private function min(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }
}
