<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_synced_at
 */
class SyncCursor extends Model
{
    use BelongsToTenant;

    protected $fillable = ['marketplace_account_id', 'resource', 'cursor', 'last_synced_at'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
