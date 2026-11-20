<?php

namespace App\Modules\Messaging\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A browser told about new orders (Web Push). `endpoint` is the push service address and is secret: whoever knows it
 * may read the last message (the service worker does, since pushes carry no payload).
 *
 * @property Carbon|null $last_sent_at
 */
class PushSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'device'];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'endpoint', 'endpoint_hash'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime', 'morning_brief' => 'boolean'];
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
