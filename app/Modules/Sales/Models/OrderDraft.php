<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Đơn nháp: the saved content of the order form (see the create_order_drafts migration). Holds no stock and is not
 * an order; "Tạo đơn" from it goes through the normal order creation and then deletes the draft.
 *
 * @property array<string, mixed> $payload
 */
class OrderDraft extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['title', 'total', 'lines_count', 'payload'];

    protected $hidden = ['id', 'tenant_id', 'created_by'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'total' => 'decimal:2', 'lines_count' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
