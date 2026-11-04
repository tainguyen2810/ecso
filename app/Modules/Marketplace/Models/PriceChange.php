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
 * One price change of a SKU: made in ECSO, taken from a website, waiting for approval, or undone on the website.
 *
 * @property Carbon $created_at
 * @property Carbon|null $decided_at
 */
class PriceChange extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const APPLIED = 'applied';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public const REVERTED = 'reverted';

    public const SOURCE_ECSO = 'ecso';

    public const SOURCE_CHANNEL = 'channel';

    protected $guarded = ['id', 'tenant_id', 'ulid'];

    protected function casts(): array
    {
        return ['old_price' => 'decimal:2', 'new_price' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
