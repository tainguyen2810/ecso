<?php

namespace App\Modules\Sales\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Nối với EzChat": the EzChat workspace this store answers to. EzChat signs every call to the store API
 * (EzChatStoreController) with `secret`; the orders it sends go to `sales_channel_id` (its warehouse is the one that
 * gives the goods) and start at `order_stage`.
 *
 * @property string $secret
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class EzChatLink extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const STAGES = ['pending', 'confirmed'];

    protected $table = 'ezchat_links';

    protected $fillable = ['order_stage'];

    protected $hidden = ['id', 'tenant_id', 'secret', 'sales_channel_id', 'linked_by'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<SalesChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }
}
