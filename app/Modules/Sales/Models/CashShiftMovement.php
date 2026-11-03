<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash put into (change money) or taken out of (to the safe, the bank) the drawer during a shift. A transfer between
 * the drawer and the business's own safe: neither income nor expense, so it is not in the cash book.
 */
class CashShiftMovement extends Model
{
    use BelongsToTenant;

    public const IN = 'in';

    public const OUT = 'out';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
