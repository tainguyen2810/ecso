<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read int|string|null $on_hand  stock join in listings
 * @property array<string, mixed>|null $subscription Thuê bao: grace_days, auto_order_days, remind
 * @property-read int|string|null $reserved  stock join in listings
 */
class Product extends Model
{
    use BelongsToTenant, HasPublicUlid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED];

    public const TYPE_STANDARD = 'standard';

    /** One SKU with no stock of its own: stock is held and shipped on its components (ProductComponent). */
    public const TYPE_COMBO = 'combo';

    /** No stock, sold once (installation, repair, consulting…). The order line keeps the SKU but never moves stock. */
    public const TYPE_SERVICE = 'service';

    /** No stock, sold for a period (1, 6, 12 months…) and renewed: hosting, maintenance, software licences. */
    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPES = [self::TYPE_STANDARD, self::TYPE_COMBO, self::TYPE_SERVICE, self::TYPE_SUBSCRIPTION];

    /** Types without stock of their own or of components: never reserved, received, counted or pushed to a marketplace. */
    public const NON_STOCK_TYPES = [self::TYPE_SERVICE, self::TYPE_SUBSCRIPTION];

    /** Renewal rules of a subscription product, with their defaults. */
    public const SUBSCRIPTION_DEFAULTS = ['grace_days' => 3, 'auto_order_days' => 7, 'remind' => true];

    public const FIELDS = ['name', 'category_id', 'brand_id', 'description', 'unit', 'tags', 'vat_rate', 'status', ...self::LOT_FIELDS];

    /** Lot / expiry settings (lô, hạn sử dụng). */
    public const LOT_FIELDS = ['track_lots', 'expiry_warn_days', 'expiry_block_days', 'shelf_life_months', 'require_mfg_date'];

    public function isCombo(): bool
    {
        return $this->type === self::TYPE_COMBO;
    }

    public function isSubscription(): bool
    {
        return $this->type === self::TYPE_SUBSCRIPTION;
    }

    /** Service or subscription: nothing to hold in a warehouse. */
    public function isNonStock(): bool
    {
        return in_array($this->type, self::NON_STOCK_TYPES, true);
    }

    /** @return array{grace_days: int, auto_order_days: int|null, remind: bool} */
    public function subscriptionRules(): array
    {
        $rules = [...self::SUBSCRIPTION_DEFAULTS, ...($this->subscription ?? [])];

        return ['grace_days' => (int) $rules['grace_days'], 'auto_order_days' => $rules['auto_order_days'] === null ? null : (int) $rules['auto_order_days'], 'remind' => (bool) $rules['remind']];
    }

    protected $fillable = self::FIELDS;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'subscription' => 'array',
            'vat_rate' => 'decimal:2',
            'track_lots' => 'boolean',
            'require_mfg_date' => 'boolean',
            'expiry_warn_days' => 'integer',
            'expiry_block_days' => 'integer',
            'shelf_life_months' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    /**
     * The product's own pictures (at most 9); a variant's picture (product_variant_id set) is not one of them.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->whereNull('product_variant_id')->orderBy('sort');
    }
}
