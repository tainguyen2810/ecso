<?php

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step in a shipment's history. Append only; dedupe_key (unique per shipment) drops a report seen twice.
 *
 * @property string $status
 * @property string $source
 * @property string|null $description
 * @property Carbon $occurred_at
 */
class ShipmentEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const SOURCE_ORDER = 'order';

    public const SOURCE_USER = 'user';

    public const SOURCE_API = 'api';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_MARKETPLACE = 'marketplace';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_COD = 'cod';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
