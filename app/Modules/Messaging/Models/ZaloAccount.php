<?php

namespace App\Modules\Messaging\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The shop's Zalo Official Account for ZNS (C6). Keys and tokens live encrypted in `secrets` and are never serialized.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $ulid
 * @property string $app_id
 * @property array{secret_key?: string, refresh_token?: string, access_token?: string}|null $secrets
 * @property Carbon|null $access_expires_at
 * @property string|null $oa_id
 * @property string|null $oa_name
 * @property array<int, array{id: string, name: string, status: string, params: array<int, array{name: string, required: bool, type: string|null, max: int|null}>}>|null $templates
 * @property array<string, array{template_id: string|null, enabled: bool, params: array<string, string>}>|null $rules
 * @property string $status
 * @property string|null $last_error
 */
class ZaloAccount extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const CONNECTED = 'connected';

    public const ERROR = 'error';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'tenant_id', 'secrets'];

    protected function casts(): array
    {
        return ['secrets' => 'encrypted:array', 'templates' => 'array', 'rules' => 'array', 'access_expires_at' => 'datetime'];
    }
}
