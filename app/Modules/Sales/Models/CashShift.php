<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cashier's shift at the counter. Cash expected in the drawer = opening float + cash received − cash refunded by
 * the cashier while the shift is open + cash put in − cash taken out.
 *
 * @property int $user_id
 * @property string $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $totals
 * @property array<string, int>|null $denominations
 */
class CashShift extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'handed_over' => 'decimal:2',
            'denominations' => 'array',
            'totals' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<CashShiftMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashShiftMovement::class)->orderBy('id');
    }
}
