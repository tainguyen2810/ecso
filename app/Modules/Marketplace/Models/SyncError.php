<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something of one store that could not be synced (today: one order), kept open until a later sync succeeds or a
 * user dismisses it. One open row per store + resource + id; repeats count as attempts.
 *
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 */
class SyncError extends Model
{
    use BelongsToTenant, HasPublicUlid;

    /** The store did not answer (timeout, 429, 5xx) or answered something unexpected. */
    public const API = 'api';

    /** The API keys were refused. */
    public const AUTH = 'auth';

    /** The order is gone from the store. */
    public const NOT_FOUND = 'not_found';

    /** The store's data cannot be taken in as it is. */
    public const DATA = 'data';

    /** The store's status cannot follow the order's status in ECSO (e.g. moving back). */
    public const CONFLICT = 'conflict';

    public const CATEGORIES = [self::API, self::AUTH, self::NOT_FOUND, self::DATA, self::CONFLICT];

    public const SYNCED = 'synced';

    public const DISMISSED = 'dismissed';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'marketplace_account_id'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MarketplaceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }
}
