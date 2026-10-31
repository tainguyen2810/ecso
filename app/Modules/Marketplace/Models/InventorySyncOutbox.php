<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pending stock push for one listing variant. Coalesced: one row per listing variant, desired_qty is the latest.
 *
 * @property Carbon|null $next_attempt_at
 */
class InventorySyncOutbox extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const UNSUPPORTED = 'unsupported';

    protected $table = 'inventory_sync_outbox';

    protected $fillable = ['marketplace_account_id', 'listing_variant_id', 'desired_qty', 'status', 'attempts', 'next_attempt_at', 'last_error'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'desired_qty' => 'integer', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<ListingVariant, $this> */
    public function listingVariant(): BelongsTo
    {
        return $this->belongsTo(ListingVariant::class);
    }
}
