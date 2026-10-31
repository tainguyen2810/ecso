<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A SKU of a marketplace listing and its link to an internal SKU.
 * Mapping is never inferred from names: only an exact seller SKU match is *suggested*, a person confirms it.
 *
 * @property Carbon|null $mapped_at
 * @property Carbon|null $last_pushed_at
 */
class ListingVariant extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const UNMAPPED = 'unmapped';

    public const SUGGESTED = 'suggested';

    public const MAPPED = 'mapped';

    public const IGNORED = 'ignored';

    protected $attributes = ['mapping_status' => self::UNMAPPED];

    protected $fillable = ['marketplace_account_id', 'listing_id', 'external_sku_id', 'seller_sku', 'name', 'price', 'marketplace_stock'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'marketplace_stock' => 'integer',
            'last_pushed_qty' => 'integer',
            'mapped_at' => 'datetime',
            'last_pushed_at' => 'datetime',
        ];
    }

    public function isMapped(): bool
    {
        return $this->mapping_status === self::MAPPED && $this->product_variant_id !== null;
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    /** @return BelongsTo<MarketplaceListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function mapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by');
    }
}
