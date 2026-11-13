<?php

namespace App\Modules\Sales\Loyalty;

use App\Modules\Analytics\Support\RevenuePolicy;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\LoyaltyEntry;
use App\Modules\Sales\Models\LoyaltyTier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tích điểm và hạng khách (C5) for orders of the shop's own channels and POS.
 *
 * Every change is a ledger row and customers.loyalty_points follows it. An order's rows are worked out again each
 * time it changes (reconcile), so completing, returning, cancelling or moving it to another buyer always leaves the
 * points it should: earned = (paid − shipping − refunded) / earn_per × the tier multiplier at completion; spent =
 * points_used unless cancelled.
 */
class LoyaltyService
{
    /** @return array{enabled: bool, earn_per: int, point_value: int, max_redeem_percent: int, expire_months: int, with_promotions: bool} */
    public static function settings(?Tenant $tenant = null): array
    {
        $s = ($tenant ? TenantSettings::for($tenant) : TenantSettings::current())->get('loyalty');

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'earn_per' => max(1, (int) ($s['earn_per'] ?? 100000)),
            'point_value' => max(0, (int) ($s['point_value'] ?? 1000)),
            'max_redeem_percent' => min(100, max(0, (int) ($s['max_redeem_percent'] ?? 50))),
            'expire_months' => max(0, (int) ($s['expire_months'] ?? 12)),
            'with_promotions' => (bool) ($s['with_promotions'] ?? true),
        ];
    }

    /** Called whenever an order is saved: brings its point rows in line with the order. Never throws on a bad state. */
    public function orderSaved(Order $order): void
    {
        if (! $order->isManual()) {
            return;
        }
        if (! $order->wasRecentlyCreated && ! $order->wasChanged(['status', 'refunded_amount', 'customer_id', 'points_used', 'total'])) {
            return;
        }
        $run = fn () => $this->reconcile($order);
        $context = app(TenantContext::class);
        $context->has() ? $run() : TenantContext::run(Tenant::query()->findOrFail($order->tenant_id), $run);
    }

    public function reconcile(Order $order): void
    {
        $entries = LoyaltyEntry::query()->where('order_id', $order->id)->whereIn('kind', [LoyaltyEntry::EARN, LoyaltyEntry::RETURN, LoyaltyEntry::REDEEM, LoyaltyEntry::REDEEM_BACK])->get();
        if ($order->customer_id === null && $entries->isEmpty()) {
            return;
        }
        $customerId = $order->customer_id !== null ? (int) $order->customer_id : null;

        // Rows left on a buyer the order no longer belongs to are taken back from them.
        foreach ($entries->where('customer_id', '!=', $customerId)->groupBy('customer_id') as $otherId => $rows) {
            $earned = (int) $rows->whereIn('kind', [LoyaltyEntry::EARN, LoyaltyEntry::RETURN])->sum('points');
            $spent = (int) $rows->whereIn('kind', [LoyaltyEntry::REDEEM, LoyaltyEntry::REDEEM_BACK])->sum('points');
            if ($earned !== 0) {
                $this->post((int) $otherId, $order, LoyaltyEntry::RETURN, -$earned, "Đơn {$order->external_order_id} chuyển sang khách khác");
            }
            if ($spent !== 0) {
                $this->post((int) $otherId, $order, LoyaltyEntry::REDEEM_BACK, -$spent, "Đơn {$order->external_order_id} chuyển sang khách khác");
            }
        }
        if ($customerId === null) {
            return;
        }
        $mine = $entries->where('customer_id', $customerId);

        // Spent: the points the order uses, given back when it is cancelled.
        $spentNow = -(int) $mine->whereIn('kind', [LoyaltyEntry::REDEEM, LoyaltyEntry::REDEEM_BACK])->sum('points');
        $spentTarget = $order->status === OrderStatus::CANCELLED ? 0 : (int) $order->points_used;
        if ($spentTarget !== $spentNow) {
            $more = $spentTarget > $spentNow;
            $this->post($customerId, $order, $more ? LoyaltyEntry::REDEEM : LoyaltyEntry::REDEEM_BACK, $spentNow - $spentTarget,
                $more ? "Dùng điểm cho đơn {$order->external_order_id}" : ($order->status === OrderStatus::CANCELLED ? "Hủy đơn {$order->external_order_id}: trả lại điểm đã dùng" : "Sửa đơn {$order->external_order_id}: trả lại điểm"));
        }

        // Earned: once the order is completed, on what the buyer paid for the goods, less what was refunded.
        $earnRow = $mine->firstWhere('kind', LoyaltyEntry::EARN);
        $earnedNow = (int) $mine->whereIn('kind', [LoyaltyEntry::EARN, LoyaltyEntry::RETURN])->sum('points');
        $settings = self::settings();
        $done = $order->completed_at !== null && in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::RETURN_REQUESTED, OrderStatus::RETURNED], true);
        if ($earnRow === null && (! $done || ! $settings['enabled'])) {
            return; // nothing earned yet, and nothing to earn now
        }
        $multiplier = $earnRow?->multiplier !== null ? (string) $earnRow->multiplier : $this->multiplierOf($customerId);
        $target = $done ? $this->pointsFor($order, $multiplier, $settings['earn_per']) : 0;
        if ($target === $earnedNow) {
            return;
        }
        if ($earnRow === null) {
            $this->post($customerId, $order, LoyaltyEntry::EARN, $target, $this->earnNote($order, $multiplier), $multiplier, $settings['expire_months'] > 0 ? now()->addMonths($settings['expire_months']) : null);
            $this->retier($customerId, upOnly: true);

            return;
        }
        $this->post($customerId, $order, $target > $earnedNow ? LoyaltyEntry::EARN : LoyaltyEntry::RETURN, $target - $earnedNow,
            $order->status === OrderStatus::CANCELLED ? "Hủy đơn {$order->external_order_id}" : "Trả hàng đơn {$order->external_order_id}");
    }

    public function pointsFor(Order $order, string $multiplier, int $earnPer): int
    {
        $base = bcsub(bcsub((string) $order->total, (string) $order->shipping_fee, 2), (string) $order->refunded_amount, 2);
        if (bccomp($base, '0', 2) <= 0) {
            return 0;
        }

        return (int) floor((float) bcdiv(bcmul($base, $multiplier, 4), (string) $earnPer, 4));
    }

    /**
     * The points an order may use, and their value. $alreadyOnOrder: points this order holds already (an edit).
     *
     * @return array{balance: int, max: int, point_value: int}
     */
    public function redeemable(?Customer $customer, string $goodsAfterDiscounts, int $alreadyOnOrder = 0): array
    {
        $settings = self::settings();
        if (! $settings['enabled'] || $customer === null || $settings['point_value'] <= 0) {
            return ['balance' => 0, 'max' => 0, 'point_value' => $settings['point_value']];
        }
        $balance = (int) $customer->loyalty_points + $alreadyOnOrder;
        $cap = (int) floor((float) $goodsAfterDiscounts * $settings['max_redeem_percent'] / 100 / $settings['point_value']);

        return ['balance' => max(0, $balance), 'max' => max(0, min($balance, $cap)), 'point_value' => $settings['point_value']];
    }

    /**
     * Puts the points the seller chose on the order: points_used, their value in points_discount, and that value added
     * to orders.discount (after the promotions, which set discount = typed + promotion).
     *
     * @param  array<string, mixed>  $data
     */
    public function applyToOrder(Order $order, array $data, int $previousPoints): void
    {
        $points = array_key_exists('points_used', $data) ? max(0, (int) $data['points_used']) : (int) $order->points_used;
        if ($points === 0 && (int) $order->points_used === 0) {
            return;
        }
        $settings = self::settings();
        if ($points > 0) {
            $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
            if (! $settings['enabled'] || $customer === null) {
                throw ValidationException::withMessages(['points_used' => 'Chỉ dùng điểm cho đơn có khách hàng, khi shop bật tích điểm.']);
            }
            if (! $settings['with_promotions'] && bccomp((string) $order->promotion_discount, '0', 2) > 0) {
                throw ValidationException::withMessages(['points_used' => 'Shop không cho dùng điểm cùng lúc với khuyến mãi.']);
            }
            $goods = bcsub($this->goods($order), (string) $order->discount, 2);
            $allowed = $this->redeemable($customer, $goods, $previousPoints);
            if ($points > $allowed['balance']) {
                throw ValidationException::withMessages(['points_used' => "Khách chỉ có {$allowed['balance']} điểm."]);
            }
            if ($points > $allowed['max']) {
                throw ValidationException::withMessages(['points_used' => "Đơn này dùng tối đa {$allowed['max']} điểm."]);
            }
        }
        $value = bcmul((string) $points, (string) $settings['point_value'], 2);
        $order->forceFill([
            'points_used' => $points,
            'points_discount' => $value,
            'discount' => bcadd((string) $order->discount, $value, 2),
        ])->save();
    }

    /** Points given or taken by hand. */
    public function adjust(Customer $customer, int $points, string $note): LoyaltyEntry
    {
        $settings = self::settings();

        return $this->post($customer->id, null, LoyaltyEntry::ADJUST, $points, $note, null, $points > 0 && $settings['expire_months'] > 0 ? now()->addMonths($settings['expire_months']) : null);
    }

    /**
     * Expires what is left of points earned before now: the oldest points are spent first, so the part of an expired
     * batch that was not spent, returned or expired already goes.
     */
    public function expire(Customer $customer): int
    {
        $rows = LoyaltyEntry::query()->where('customer_id', $customer->id)->get(['kind', 'points', 'expires_at']);
        $due = (int) $rows->filter(fn ($r) => $r->points > 0 && $r->expires_at !== null && $r->expires_at->isPast() && in_array($r->kind, [LoyaltyEntry::EARN, LoyaltyEntry::ADJUST], true))->sum('points');
        if ($due === 0) {
            return 0;
        }
        $used = -(int) $rows->filter(fn ($r) => $r->points < 0)->sum('points') - (int) $rows->where('kind', LoyaltyEntry::REDEEM_BACK)->sum('points');
        $points = min(max(0, $due - $used), max(0, (int) $customer->loyalty_points));
        if ($points > 0) {
            $this->post($customer->id, null, LoyaltyEntry::EXPIRE, -$points, 'Điểm hết hạn');
        }

        return $points;
    }

    /** Spending of the last 12 months that counts for the tier: revenue orders of own channels, less refunds. */
    public function spent12(int $customerId): string
    {
        return (string) (Order::query()->where('customer_id', $customerId)->where('marketplace', Order::SOURCE_MANUAL)
            ->whereIn('status', RevenuePolicy::STATUSES)->where('placed_at', '>=', now()->subMonths(12))
            ->sum(DB::raw('total - refunded_amount')) ?: '0');
    }

    /**
     * Puts the customer in the tier their spending reaches. upOnly: right after a purchase only moves up; the nightly
     * run also moves down. A group set by hand that is not a tier's group is never changed.
     */
    public function retier(int $customerId, bool $upOnly = false): ?LoyaltyTier
    {
        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            return null;
        }
        $tiers = LoyaltyTier::query()->orderByDesc('min_spent')->get();
        if ($tiers->isEmpty()) {
            return null;
        }
        $spent = $this->spent12($customerId);
        $tier = $tiers->first(fn (LoyaltyTier $t) => bccomp($spent, (string) $t->min_spent, 2) >= 0);
        $current = $tiers->firstWhere('id', $customer->loyalty_tier_id);
        if ($tier?->id === $current?->id) {
            return $tier;
        }
        if ($upOnly && $current !== null && ($tier === null || bccomp((string) $tier->min_spent, (string) $current->min_spent, 2) < 0)) {
            return $current;
        }
        $up = $current === null || ($tier !== null && bccomp((string) $tier->min_spent, (string) $current->min_spent, 2) > 0);
        $updates = ['loyalty_tier_id' => $tier?->id];
        $tierGroups = $tiers->pluck('customer_group_id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($customer->customer_group_id === null || in_array((int) $customer->customer_group_id, $tierGroups, true)) {
            $updates['customer_group_id'] = $tier?->customer_group_id ?? $customer->customer_group_id;
        }
        Customer::query()->whereKey($customerId)->update($updates);
        $money = number_format((float) $spent, 0, ',', '.');
        LoyaltyEntry::query()->create([
            'customer_id' => $customerId, 'kind' => LoyaltyEntry::TIER, 'points' => 0, 'created_by' => Auth::id(),
            'note' => $tier === null ? 'Ra khỏi hạng (chi tiêu 12 tháng chưa đủ mức)' : ($up ? 'Lên' : 'Xuống')." hạng {$tier->name} (chi tiêu 12 tháng {$money} ₫)",
        ]);

        return $tier;
    }

    private function multiplierOf(int $customerId): string
    {
        $tierId = Customer::query()->whereKey($customerId)->value('loyalty_tier_id');

        return $tierId ? (string) (LoyaltyTier::query()->whereKey($tierId)->value('point_multiplier') ?? '1') : '1.00';
    }

    private function earnNote(Order $order, string $multiplier): string
    {
        $paid = number_format((float) bcsub((string) $order->total, (string) $order->shipping_fee, 2), 0, ',', '.');

        return "Đơn {$order->external_order_id} hoàn tất · {$paid} ₫".(bccomp($multiplier, '1', 2) !== 0 ? ' × '.rtrim(rtrim(number_format((float) $multiplier, 2, ',', ''), '0'), ',') : '');
    }

    private function goods(Order $order): string
    {
        $sum = '0';
        foreach ($order->items()->get(['quantity', 'unit_price', 'discount']) as $i) {
            $line = bcsub(bcmul((string) $i->unit_price, (string) $i->quantity, 2), (string) $i->discount, 2);
            $sum = bcadd($sum, bccomp($line, '0', 2) > 0 ? $line : '0', 2);
        }

        return $sum;
    }

    private function post(int $customerId, ?Order $order, string $kind, int $points, string $note, ?string $multiplier = null, mixed $expiresAt = null): LoyaltyEntry
    {
        $entry = LoyaltyEntry::query()->create([
            'customer_id' => $customerId, 'order_id' => $order?->id, 'kind' => $kind, 'points' => $points, 'note' => mb_substr($note, 0, 255),
            'multiplier' => $multiplier, 'expires_at' => $expiresAt, 'created_by' => Auth::id(),
        ]);
        if ($points !== 0) {
            Customer::query()->whereKey($customerId)->increment('loyalty_points', $points);
        }

        return $entry;
    }
}
