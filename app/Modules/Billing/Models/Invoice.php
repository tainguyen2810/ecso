<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bill for a subscription period. Paid only by recording a payment (PaymentService); never marked paid by hand.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property string $number
 * @property string $kind
 * @property string $billing_cycle
 * @property string $subtotal
 * @property string $credit
 * @property string $amount
 * @property string $status
 * @property string $transfer_code
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $cancelled_at
 */
class Invoice extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const KIND_SUBSCRIBE = 'subscribe';

    public const KIND_UPGRADE = 'upgrade';

    public const KIND_RENEWAL = 'renewal';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'plan_id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'credit' => 'decimal:2', 'amount' => 'decimal:2',
            'due_at' => 'datetime', 'paid_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
