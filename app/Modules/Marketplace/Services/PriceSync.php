<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\UpdatesPrices;
use App\Modules\Marketplace\Jobs\PushPricesJob;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PriceChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Two-way price sync between ECSO and websites (WooCommerce). Per SKU, one side holds the selling price
 * ("Nguồn giá"): auto = ECSO while the SKU has stock, the website when it is out of stock; or fixed to one side.
 *
 * - ECSO holds: a price change in ECSO is pushed to the website's regular price; a change made on the website is
 *   undone (pushed back) and logged as "reverted".
 * - Website holds: the website's real selling price (sale price if any, else regular) becomes ECSO's retail price.
 *   A price of 0 or one more than 50% away from ECSO's waits for approval instead.
 *
 * Loop guard: applying a website price mutes the "ECSO price changed" hook, and a website price equal to ECSO's
 * (what we pushed) changes nothing.
 */
class PriceSync
{
    /** Website prices further than this from ECSO's (fraction) wait for approval. */
    public const MAX_AUTO_DEVIATION = 0.5;

    private static bool $muted = false;

    public function __construct(private readonly StockReader $stock, private readonly ConnectorRegistry $connectors) {}

    /** "ecso" or "channel": who holds the price of this SKU right now. */
    public function holder(ProductVariant $variant, ?int $onHand = null): string
    {
        return match ($variant->price_source ?? 'auto') {
            'ecso' => PriceChange::SOURCE_ECSO,
            'woo' => PriceChange::SOURCE_CHANNEL,
            default => ($onHand ?? $this->stock->onHand([$variant->id])[$variant->id] ?? 0) > 0 ? PriceChange::SOURCE_ECSO : PriceChange::SOURCE_CHANNEL,
        };
    }

    /** Can ECSO set prices on this shop, and does the shop want it? */
    public function enabledFor(MarketplaceAccount $account): bool
    {
        return $account->isActive() && $account->syncs('sync_prices') && $this->connectors->has($account->marketplace)
            && $this->connectors->for($account->marketplace) instanceof UpdatesPrices;
    }

    /** Hook on ProductVariant "updated": logs an ECSO price change and pushes it where ECSO holds the price. */
    public function variantUpdated(ProductVariant $variant): void
    {
        if (self::$muted) {
            return;
        }

        $priceChanged = $variant->wasChanged('ref_price');
        if ($priceChanged) {
            $old = $variant->getOriginal('ref_price');
            PriceChange::query()->create([
                'product_variant_id' => $variant->id,
                'old_price' => $old,
                'new_price' => $variant->ref_price ?? 0,
                'source' => PriceChange::SOURCE_ECSO,
                'status' => PriceChange::APPLIED,
                'reason' => 'Sửa giá trong ECSO',
                'actor_id' => Auth::id(),
            ]);
            ProductVariant::query()->whereKey($variant->id)->update(['price_updated_from' => 'ecso', 'price_updated_at' => now()]);
        }

        if ($priceChanged || $variant->wasChanged('price_source')) {
            $this->queueEcsoPrice($variant);
        }
    }

    /**
     * Queues ECSO's price for every linked website SKU when ECSO holds the price.
     *
     * @return int SKUs queued
     */
    public function queueEcsoPrice(ProductVariant $variant): int
    {
        if (self::money($variant->getAttribute('ref_price')) === null || $this->holder($variant) !== PriceChange::SOURCE_ECSO) {
            return 0;
        }

        $queued = 0;
        $accounts = [];
        foreach ($this->linked($variant->id) as $listing) {
            if (! $this->enabledFor($listing->account)) {
                continue;
            }
            $differs = ! $this->same($listing->regular_price, $variant->ref_price);
            $listing->forceFill([
                'target_price' => $variant->ref_price,
                'price_push_status' => $differs ? 'pending' : 'ok',
                'price_push_error' => null,
            ])->save();
            if ($differs) {
                $queued++;
                $accounts[$listing->marketplace_account_id] = true;
            }
        }

        foreach (array_keys($accounts) as $accountId) {
            PushPricesJob::dispatch($accountId)->afterCommit();
        }

        return $queued;
    }

    /** Called after a website SKU was (re)read from the store. */
    public function onChannelPrice(MarketplaceAccount $account, ListingVariant $listing): void
    {
        if (! $listing->isMapped() || ! $this->enabledFor($account)) {
            return;
        }
        $variant = ProductVariant::query()->find($listing->product_variant_id);
        if (! $variant) {
            return;
        }

        $this->holder($variant) === PriceChange::SOURCE_ECSO
            ? $this->guardEcsoPrice($account, $listing, $variant)
            : $this->takeChannelPrice($account, $listing, $variant);
    }

    /** ECSO holds: anything else on the website is undone. */
    private function guardEcsoPrice(MarketplaceAccount $account, ListingVariant $listing, ProductVariant $variant): void
    {
        $ref = self::money($variant->getAttribute('ref_price'));
        if ($ref === null || self::money($listing->getAttribute('regular_price')) === null || $this->same($listing->regular_price, $ref)) {
            if ($listing->price_push_status === 'pending' && $this->same($listing->regular_price, $listing->target_price)) {
                $listing->forceFill(['price_push_status' => 'ok'])->save();
            }

            return;
        }

        // Someone changed the price on the website after ECSO had set it: log it, then put ECSO's price back.
        if ($listing->pushed_price !== null && ! $this->same($listing->regular_price, $listing->pushed_price)) {
            PriceChange::query()->create([
                'product_variant_id' => $variant->id,
                'marketplace_account_id' => $account->id,
                'listing_variant_id' => $listing->id,
                'old_price' => $variant->ref_price,
                'new_price' => $listing->regular_price,
                'source' => PriceChange::SOURCE_CHANNEL,
                'status' => PriceChange::REVERTED,
                'reason' => 'Sửa giá trên website, trả lại giá ECSO (ECSO đang giữ giá)',
            ]);
        }

        $listing->forceFill(['target_price' => $variant->ref_price, 'price_push_status' => 'pending', 'price_push_error' => null])->save();
        PushPricesJob::dispatch($account->id)->afterCommit();
    }

    /** The website holds: its real selling price (sale price if any) becomes ECSO's, or waits for approval. */
    private function takeChannelPrice(MarketplaceAccount $account, ListingVariant $listing, ProductVariant $variant): void
    {
        $price = self::money($listing->getAttribute('price'));
        $current = self::money($variant->getAttribute('ref_price'));
        $pending = PriceChange::query()->where('listing_variant_id', $listing->id)->where('status', PriceChange::PENDING)->first();

        if ($price === null || $this->same($price, $current)) {
            $pending?->forceFill(['status' => PriceChange::REJECTED, 'reason' => 'Giá website đã bằng giá ECSO', 'decided_at' => now()])->save();

            return;
        }

        $reason = $this->needsApproval($price, $current);
        if ($reason !== null) {
            // A price the owner already refused stays refused until the website changes it again.
            $refused = PriceChange::query()->where('listing_variant_id', $listing->id)->where('status', PriceChange::REJECTED)
                ->where('decided_by', '!=', null)->latest('id')->first();
            if ($refused && $this->same($refused->new_price, $price)) {
                return;
            }
            $attributes = [
                'product_variant_id' => $variant->id,
                'marketplace_account_id' => $account->id,
                'old_price' => $current,
                'new_price' => $price,
                'source' => PriceChange::SOURCE_CHANNEL,
                'status' => PriceChange::PENDING,
                'reason' => $reason,
            ];
            $pending ? $pending->forceFill($attributes)->save() : PriceChange::query()->create([...$attributes, 'listing_variant_id' => $listing->id]);

            return;
        }

        $pending?->forceFill(['status' => PriceChange::REJECTED, 'reason' => 'Thay bằng giá website mới hơn', 'decided_at' => now()])->save();
        $onSale = $listing->sale_price !== null && (float) $listing->sale_price > 0;
        $this->apply($variant, $price, [
            'marketplace_account_id' => $account->id,
            'listing_variant_id' => $listing->id,
            'reason' => ($variant->price_source === 'woo' ? 'Nguồn giá là website' : 'Hết hàng, lấy giá website').($onSale ? ' (giá khuyến mãi)' : ''),
        ]);
    }

    /** Why a website price may not be taken automatically, or null when it may. */
    public function needsApproval(string $price, ?string $current): ?string
    {
        if ((float) $price <= 0) {
            return 'Giá bằng 0';
        }
        if ($current !== null && (float) $current > 0 && abs((float) $price - (float) $current) / (float) $current > self::MAX_AUTO_DEVIATION) {
            return 'Lệch quá 50%';
        }

        return null;
    }

    /** Applies a waiting website price. */
    public function approve(PriceChange $change): PriceChange
    {
        abort_unless($change->status === PriceChange::PENDING, 422, 'Giá này đã được xử lý.');
        $variant = ProductVariant::query()->findOrFail($change->product_variant_id);

        DB::transaction(function () use ($change, $variant) {
            $old = $variant->ref_price;
            $this->setPrice($variant, (string) $change->new_price);
            $change->forceFill(['old_price' => $old, 'status' => PriceChange::APPLIED, 'decided_by' => Auth::id(), 'decided_at' => now()])->save();
        });

        return $change;
    }

    public function reject(PriceChange $change): PriceChange
    {
        abort_unless($change->status === PriceChange::PENDING, 422, 'Giá này đã được xử lý.');
        $change->forceFill(['status' => PriceChange::REJECTED, 'decided_by' => Auth::id(), 'decided_at' => now()])->save();

        return $change;
    }

    /**
     * Approves every waiting price of a shop.
     *
     * @return int prices applied
     */
    public function approveAll(MarketplaceAccount $account): int
    {
        $changes = PriceChange::query()->where('marketplace_account_id', $account->id)->where('status', PriceChange::PENDING)->get();
        $changes->each(fn (PriceChange $c) => $this->approve($c));

        return $changes->count();
    }

    /** @param  array<string, mixed>  $change */
    private function apply(ProductVariant $variant, string $price, array $change): void
    {
        DB::transaction(function () use ($variant, $price, $change) {
            $old = $variant->ref_price;
            $this->setPrice($variant, $price, 'website');
            PriceChange::query()->create([
                ...$change,
                'product_variant_id' => $variant->id,
                'old_price' => $old,
                'new_price' => $price,
                'source' => PriceChange::SOURCE_CHANNEL,
                'status' => PriceChange::APPLIED,
            ]);
        });
    }

    private function setPrice(ProductVariant $variant, string $price, string $from = 'website'): void
    {
        self::$muted = true;
        try {
            $variant->forceFill(['ref_price' => $price, 'price_updated_from' => $from, 'price_updated_at' => now()])->save();
        } finally {
            self::$muted = false;
        }
    }

    /** @return Collection<int, ListingVariant> */
    private function linked(int $variantId): Collection
    {
        return ListingVariant::query()->with('account', 'listing')->where('product_variant_id', $variantId)
            ->where('mapping_status', ListingVariant::MAPPED)->get();
    }

    /** A money attribute as a string (decimal casts), or null. */
    private static function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function same(mixed $a, mixed $b): bool
    {
        return $a !== null && $b !== null && abs((float) $a - (float) $b) < 0.005;
    }
}
