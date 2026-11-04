<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The books closed up to a day (khóa sổ): no money row dated on or before it can be added, voided or backdated
 * there, so balances already reported stay as they were. The fund balances at that day are kept as a snapshot.
 * Reopening keeps the row, stamped, for the history.
 *
 * @property Carbon $closed_until
 * @property array<string, string> $balances
 * @property string|null $note
 * @property Carbon|null $reopened_at
 * @property string|null $reopen_reason
 */
class CashClosing extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'created_by', 'reopened_by'];

    protected function casts(): array
    {
        return ['closed_until' => 'date', 'balances' => 'array', 'reopened_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
