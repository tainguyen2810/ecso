<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fee table of one shop (category_id null) or a category override inside it (null fields = as the shop).
 *
 * @property string|null $commission_pct
 * @property string|null $payment_pct
 * @property string|null $service_pct
 * @property string|null $fixed_fee
 * @property string $min_margin_pct
 * @property array<string, mixed>|null $suggested_from
 */
class ChannelPriceRule extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const FEE_FIELDS = ['commission_pct', 'payment_pct', 'service_pct', 'fixed_fee'];

    protected $guarded = ['id', 'tenant_id', 'ulid'];

    protected function casts(): array
    {
        return [
            'commission_pct' => 'decimal:2',
            'payment_pct' => 'decimal:2',
            'service_pct' => 'decimal:2',
            'fixed_fee' => 'decimal:2',
            'rounding' => 'integer',
            'min_margin_pct' => 'decimal:2',
            'auto_apply' => 'boolean',
            'auto_update_fees' => 'boolean',
            'suggested_from' => 'array',
        ];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
