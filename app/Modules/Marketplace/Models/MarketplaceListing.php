<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
