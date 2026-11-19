<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Catalog\Http\Controllers\ProductImageController;
use App\Modules\Catalog\Jobs\DownloadProductImagesJob;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\RemoteImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Lấy ảnh từ sàn": the pictures of the marketplace products a product is linked to, copied into the shop's own
 * storage (never kept as a link to the marketplace, which may remove it). Product pictures go to the product's gallery;
 * a colour's picture goes to the variants of that colour.
 */
class MarketplaceImages
{
    public function __construct(private readonly TenantContext $context, private readonly RemoteImage $remote) {}

    /**
     * What each linked shop has for one product.
     *
     * @return list<array{account: array{id: string, name: string, marketplace: string}, images: list<string>, variants: list<array{url: string, label: string, variant_ids: list<string>}>}>
     */
    public function forProduct(Product $product): array
    {
        $variants = $product->variants()->pluck('ulid', 'id');
        $rows = ListingVariant::query()->with(['listing', 'account'])->where('mapping_status', ListingVariant::MAPPED)
            ->whereIn('product_variant_id', $variants->keys())->orderBy('id')->get();

        return $rows->groupBy('marketplace_account_id')->map(function (Collection $group) use ($variants) {
            /** @var MarketplaceAccount $account */
            $account = $group->first()->account;
            $images = $group->pluck('listing')->filter()->unique('id')->flatMap(fn ($l) => self::listingImages($l->raw, $l->image_url))->unique()->take(ProductImageController::MAX_IMAGES)->values()->all();
            $byUrl = [];
            foreach ($group as $row) {
                $url = $row->listing?->raw['variants'][$row->external_sku_id]['image_url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $byUrl[$url] ??= ['url' => $url, 'label' => self::colour($row), 'variant_ids' => []];
                    $byUrl[$url]['variant_ids'][] = $variants[$row->product_variant_id];
                }
            }

            return [
                'account' => ['id' => $account->ulid, 'name' => (string) $account->shop_name, 'marketplace' => $account->marketplace],
                'images' => $images,
                'variants' => array_values(array_map(fn ($v) => [...$v, 'variant_ids' => array_values(array_unique($v['variant_ids']))], $byUrl)),
            ];
        })->filter(fn ($g) => $g['images'] !== [] || $g['variants'] !== [])->values()->all();
    }

    /**
     * Copies the chosen pictures now (the product page's picker): product pictures up to the 9 a product holds, then
     * each colour picture to its variants.
     *
     * @param  list<string>  $urls
     * @param  list<array{url: string, variant_ids: list<string>}>  $variantImages
     * @return array{added: int, failed: int}
     */
    public function copy(Product $product, array $urls, array $variantImages): array
    {
        $sources = $this->forProduct($product);
        $allowed = collect($sources)->flatMap(fn ($g) => [...$g['images'], ...array_column($g['variants'], 'url')])->flip();
        $ids = $product->variants()->pluck('id', 'ulid');
        $result = ['added' => 0, 'failed' => 0];
        $room = ProductImageController::MAX_IMAGES - $product->images()->count();
        $sort = (int) $product->images()->max('sort');

        foreach (array_unique($urls) as $url) {
            if (! $allowed->has($url) || $room <= 0) {
                continue;
            }
            $path = $this->store($url);
            if ($path === null) {
                $result['failed']++;

                continue;
            }
            $image = new ProductImage(['path' => $path, 'sort' => ++$sort]);
            $image->product()->associate($product)->save();
            $room--;
            $result['added']++;
        }

        foreach ($variantImages as $v) {
            $variantIds = collect($v['variant_ids'])->map(fn ($u) => $ids[$u] ?? null)->filter()->values()->all();
            if (! $allowed->has($v['url']) || $variantIds === []) {
                continue;
            }
            $path = $this->store($v['url']);
            if ($path === null) {
                $result['failed']++;

                continue;
            }
            DB::transaction(function () use ($product, $variantIds, $path) {
                ProductImage::query()->whereIn('product_variant_id', $variantIds)->delete();
                foreach ($variantIds as $variantId) {
                    $image = new ProductImage(['path' => $path, 'sort' => 0, 'product_variant_id' => $variantId]);
                    $image->product()->associate($product)->save();
                }
            });
            $result['added']++;
        }

        return $result;
    }

    /** Products without a picture that a linked shop has pictures for ("27 sản phẩm chưa có ảnh nhưng đang bán trên sàn"). */
    public function missingCount(): int
    {
        return $this->missingQuery()->count();
    }

    /**
     * Queues the download of the marketplace pictures for products that have none: their gallery and their colours.
     *
     * @param  list<string>|null  $productUlids  null = every product without a picture
     * @return array{queued: int}
     */
    public function queueMissing(?array $productUlids = null): array
    {
        return ['queued' => $this->queue($this->missingQuery()->when($productUlids !== null, fn ($q) => $q->whereIn('ulid', $productUlids))->limit(500)->get())];
    }

    /**
     * Queues the marketplace pictures of these products (those already holding pictures keep them).
     *
     * @param  iterable<Product>  $products
     */
    public function queue(iterable $products): int
    {
        $jobs = [];
        foreach ($products as $product) {
            $entries = $this->entries($product);
            if ($entries !== []) {
                $jobs[$product->id] = $entries;
            }
        }
        foreach (array_chunk($jobs, 50, true) as $chunk) {
            DownloadProductImagesJob::dispatch((int) $this->context->id(), $chunk);
        }

        return count($jobs);
    }

    /**
     * Download entries for one product: the gallery of the first shop that has one, then each colour picture.
     *
     * @return list<string|array{url: string, variant_ids: list<int>}>
     */
    public function entries(Product $product): array
    {
        $sources = $this->forProduct($product);
        $ids = $product->variants()->pluck('id', 'ulid');
        $gallery = collect($sources)->first(fn ($g) => $g['images'] !== [])['images'] ?? [];
        $colours = [];
        foreach ($sources as $g) {
            foreach ($g['variants'] as $v) {
                $colours[] = ['url' => $v['url'], 'variant_ids' => collect($v['variant_ids'])->map(fn ($u) => (int) $ids[$u])->all()];
            }
        }

        return [...$gallery, ...$colours];
    }

    /**
     * The pictures of a marketplace product kept on its listing: all of them, else the cover.
     *
     * @param  array<string, mixed>|null  $raw
     * @return list<string>
     */
    public static function listingImages(?array $raw, ?string $cover): array
    {
        $images = array_values(array_filter((array) ($raw['details']['images'] ?? []), fn ($u) => is_string($u) && $u !== ''));

        return $images !== [] ? $images : ($cover ? [$cover] : []);
    }

    /** @return Builder<Product> */
    private function missingQuery()
    {
        $linked = ListingVariant::query()->where('mapping_status', ListingVariant::MAPPED)->whereNotNull('product_variant_id')
            ->whereHas('listing', fn ($l) => $l->whereNotNull('image_url'))->select('product_variant_id');

        return Product::query()->whereDoesntHave('images')
            ->whereIn('id', ProductVariant::query()->whereIn('id', $linked)->select('product_id'));
    }

    /** "Navy" of "Màu: Navy, Size: M": the first option, which is the one Shopee gives pictures to. */
    private static function colour(ListingVariant $row): string
    {
        $options = (array) ($row->listing?->raw['variants'][$row->external_sku_id]['options'] ?? []);

        // Without options the SKU name is "Trắng, M" or "Trắng / M": the picture belongs to the first part (the colour).
        return (string) (reset($options) ?: trim(preg_split('~\s*[,/]\s*~u', (string) $row->name)[0] ?? ''));
    }

    private function store(string $url): ?string
    {
        $image = $this->remote->fetch($url);
        if ($image === null) {
            return null;
        }
        $path = "tenants/{$this->context->tenant()->ulid}/products/".Str::random(40).'.'.$image['extension'];
        Storage::disk('public')->put($path, $image['body']);

        return $path;
    }
}
