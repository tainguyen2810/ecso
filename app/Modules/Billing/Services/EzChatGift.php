<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Đợt 17 "Gói tặng kèm": a business opened from EzChat gets a banhang plan for as long as its EzChat plan runs
 * (EZCHAT_GIFT_PLANS maps EzChat plan => banhang plan). Refreshed at each "Đăng nhập bằng EzChat".
 *
 * A gift never takes the place of a plan the business paid for itself; it issues no invoice, and when it ends (the
 * EzChat plan expired or dropped to one without a gift) the business moves to Free like any ended plan.
 */
class EzChatGift
{
    public const SOURCE = 'ezchat';

    /** Without an end date on the EzChat side, the gift runs this many days from the last login. */
    public const ROLLING_DAYS = 31;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** @return array<string, string> EzChat plan slug => banhang plan key */
    public static function map(): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('marketplaces.ezchat.gift_plans')))) as $pair) {
            [$from, $to] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            if ($from !== '' && $to !== '') {
                $out[strtolower($from)] = $to;
            }
        }

        return $out;
    }

    /**
     * Applies the gift for the current tenant from the EzChat plan in the login ticket.
     *
     * @param  array{slug?: string|null, name?: string|null, expiresAt?: string|null, active?: bool|null}  $ezchatPlan
     * @return string what happened: given, renewed, ended, kept_own_plan, none
     */
    public function apply(array $ezchatPlan): string
    {
        return DB::transaction(function () use ($ezchatPlan) {
            $sub = Subscription::query()->with('plan')->lockForUpdate()->firstOrFail();
            $isGift = $sub->gift_source === self::SOURCE;
            $slug = strtolower((string) ($ezchatPlan['slug'] ?? ''));
            $target = ($ezchatPlan['active'] ?? true) ? (self::map()[$slug] ?? null) : null;
            $plan = $target ? Plan::query()->where('key', $target)->first() : null;
            $end = filled($ezchatPlan['expiresAt'] ?? null) ? Carbon::parse($ezchatPlan['expiresAt']) : Carbon::now()->addDays(self::ROLLING_DAYS);

            if (! $plan || $end->isPast()) {
                if ($isGift) {
                    $this->subscriptions->switchTo($sub, Plan::query()->where('key', Plan::FREE)->firstOrFail(), 'monthly', Subscription::STATUS_ACTIVE, now(), null);

                    return 'ended';
                }

                return 'none';
            }
            // A plan the business pays for itself stays.
            if (! $isGift && $this->subscriptions->isPaidAndRunning($sub) && $sub->current_period_end?->isFuture()) {
                return 'kept_own_plan';
            }

            $renew = $isGift && $sub->plan_id === $plan->id;
            if (! $renew || ! $sub->current_period_end?->equalTo($end)) {
                $this->subscriptions->switchTo($sub, $plan, 'monthly', Subscription::STATUS_ACTIVE, $renew ? $sub->current_period_start : now(), $end);
            }
            $sub->forceFill(['gift_source' => self::SOURCE, 'gift_label' => mb_substr('EzChat '.((string) ($ezchatPlan['name'] ?? $slug)), 0, 100)])->save();

            return $renew ? 'renewed' : 'given';
        });
    }
}
