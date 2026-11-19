<?php

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Concerns\RunsOnLongQueue;
use App\Shared\Support\RemoteImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads the pictures of imported products (links from a Sapo or KiotViet file, or a marketplace's product) into
 * EzSell's own storage. A product that already has pictures when the job runs is left alone, so running the job twice
 * adds nothing; the same goes for a variant's own picture (an entry with variant_ids, a colour on the marketplace).
 * A link that fails (gone, not a picture, too big, a private address) is skipped and logged.
 */
class DownloadProductImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout = 1800;

    /** @param  array<int, list<string|array{url: string, variant_ids: list<int>}>>  $images  product id => picture links */
    public function __construct(public readonly int $tenantId, public readonly array $images)
    {
        $this->onLongQueue();
    }

    public function handle(RemoteImage $remote): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        TenantContext::run($tenant, function () use ($tenant, $remote) {
            foreach ($this->images as $productId => $entries) {
                $product = Product::query()->find($productId);
                if ($product === null) {
                    continue;
                }
                $ownPictures = ! $product->images()->exists();
                $sort = 0;
                foreach ($entries as $entry) {
                    $url = is_array($entry) ? $entry['url'] : $entry;
                    $variantIds = is_array($entry) ? array_values(array_diff($entry['variant_ids'],
                        ProductImage::query()->whereIn('product_variant_id', $entry['variant_ids'])->pluck('product_variant_id')->all())) : [];
                    if (is_array($entry) ? $variantIds === [] : ! $ownPictures) {
                        continue;
                    }
                    $path = $this->store($remote, $tenant, $productId, $url);
                    if ($path === null) {
                        continue;
                    }
                    foreach (is_array($entry) ? $variantIds : [null] as $variantId) {
                        $model = new ProductImage(['path' => $path, 'sort' => $variantId === null ? ++$sort : 0, 'product_variant_id' => $variantId]);
                        $model->product()->associate($product)->save();
                    }
                }
            }
        });
    }

    private function store(RemoteImage $remote, Tenant $tenant, int $productId, string $url): ?string
    {
        $image = $remote->fetch($url);
        if ($image === null) {
            Log::info('product image skipped', ['tenant' => $tenant->id, 'product' => $productId, 'url' => Str::limit($url, 200)]);

            return null;
        }
        $path = "tenants/{$tenant->ulid}/products/".Str::random(40).'.'.$image['extension'];
        Storage::disk('public')->put($path, $image['body']);

        return $path;
    }
}
