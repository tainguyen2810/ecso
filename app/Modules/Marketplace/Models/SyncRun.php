<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One pass of pulling data from a store or marketplace (today: orders changed in a time window).
 *
 * @property array<string, int>|null $counts
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class SyncRun extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const RUNNING = 'running';

    public const OK = 'ok';

    /** Finished, but some orders could not be read; the cursor stays so the next run tries them again. */
    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'marketplace_account_id'];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }
}
