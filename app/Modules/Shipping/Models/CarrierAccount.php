<?php

namespace App\Modules\Shipping\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The shop's own account at a carrier. Credentials are encrypted with APP_KEY and never serialized.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $ulid
 * @property string $carrier
 * @property string $name
 * @property string|null $external_account
 * @property string $environment
 * @property string $status
 * @property array<string, mixed>|null $credentials
 * @property string $webhook_token
 * @property array<int|string, array{id: string, name: string, address?: string|null}>|null $pickups warehouse id => carrier pickup point
 * @property array<int, array{id: string, name: string, address?: string|null, province_code?: string|null}>|null $locations pickup points the carrier knows for this account
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $last_event_at
 * @property string|null $last_error
 */
class CarrierAccount extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const CONNECTED = 'connected';

    /** The carrier refused the credentials last time: shown on the page, not offered for new parcels. */
    public const ERROR = 'error';

    public const DISCONNECTED = 'disconnected';

    public const SANDBOX = 'sandbox';

    public const PRODUCTION = 'production';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'tenant_id', 'credentials', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'pickups' => 'array',
            'locations' => 'array',
            'last_verified_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CarrierAccount $account) {
            $account->webhook_token ??= Str::random(48);
        });
    }

    public function isUsable(): bool
    {
        return $this->status === self::CONNECTED;
    }

    /** The carrier's pickup point for a warehouse: the mapped one, else the first the carrier knows. */
    public function pickupFor(?int $warehouseId): ?array
    {
        $pickups = $this->pickups ?? [];
        if ($warehouseId !== null && isset($pickups[$warehouseId])) {
            return $pickups[$warehouseId];
        }

        return $this->locations[0] ?? null;
    }
}
