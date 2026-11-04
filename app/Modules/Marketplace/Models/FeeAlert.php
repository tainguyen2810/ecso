<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the daily fee check found: a marketplace charging more (or less) than the fee table, or orders sold at
 * a loss after fees.
 *
 * @property array<string, mixed> $data
 */
class FeeAlert extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const FEE_DRIFT = 'fee_drift';

    public const LOSS_ORDERS = 'loss_orders';

    public const OPEN = 'open';

    protected $guarded = ['id', 'tenant_id', 'ulid'];

    protected function casts(): array
    {
        return ['data' => 'array', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }
}
