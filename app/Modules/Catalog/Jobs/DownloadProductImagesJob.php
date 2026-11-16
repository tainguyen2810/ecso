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
 * Downloads the pictures of imported products (links from a Sapo or KiotViet file) into EzSell's own storage.
 * A product that already has pictures when the job runs is left alone, so running the job twice adds nothing.
 * A link that fails (gone, not a picture, too big, a private address) is skipped and logged.
 */
class DownloadProductImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout = 1800;

    /** @param  array<int, list<string>>  $images  product id => picture links */
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
            foreach ($this->images as $productId => $urls) {
                $product = Product::query()->find($productId);
                if ($product === null || $product->images()->exists()) {
                    continue;
                }
                $sort = 0;
                foreach ($urls as $url) {
                    $image = $remote->fetch($url);
                    if ($image === null) {
                        Log::info('product image skipped', ['tenant' => $tenant->id, 'product' => $productId, 'url' => Str::limit($url, 200)]);

                        continue;
                    }
                    $path = "tenants/{$tenant->ulid}/products/".Str::random(40).'.'.$image['extension'];
                    Storage::disk('public')->put($path, $image['body']);
                    $model = new ProductImage(['path' => $path, 'sort' => ++$sort]);
                    $model->product()->associate($product)->save();
                }
            }
        });
    }
}
