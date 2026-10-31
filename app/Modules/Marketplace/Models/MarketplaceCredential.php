<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * API tokens of a shop. Encrypted at rest with APP_KEY; hidden from every serialization.
 *
 * @property Carbon|null $access_expires_at
 * @property Carbon|null $refresh_expires_at
 */
class MarketplaceCredential extends Model
{
    use BelongsToTenant;

    protected $fillable = ['marketplace_account_id', 'access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at', 'scopes'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }
}
