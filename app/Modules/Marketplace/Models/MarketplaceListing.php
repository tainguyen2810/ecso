<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product on a marketplace. raw keeps what a new ECSO product is made from: details (description, categories,
 * weight, images) and per SKU its options, weight, barcode and picture (image_url).
 *
 * @property array{details?: array<string, mixed>, variants?: array<string, array<string, mixed>>}|null $raw
 * @property string|null $image_url
 */
class MarketplaceListing extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['marketplace_account_id', 'external_product_id', 'title', 'status', 'image_url', 'raw', 'synced_at'];

    protected function casts(): array
    {
        return ['raw' => 'array', 'synced_at' => 'datetime'];
    }

    /** @return HasMany<ListingVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ListingVariant::class, 'listing_id');
    }
}
