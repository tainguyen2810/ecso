<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $cancel_at
 * @property Carbon|null $trial_used_at
 * @property Carbon|null $past_due_since
 * @property int $plan_id
 * @property int|null $pending_plan_id
 * @property string $status
 * @property string $billing_cycle
 */
class Subscription extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRIALING = 'trialing';

    /** Paid period ended without payment: features kept during the grace period, then the tenant moves to Free. */
    public const STATUS_PAST_DUE = 'past_due';

    protected $fillable = ['plan_id', 'status', 'billing_cycle', 'trial_ends_at', 'current_period_start', 'current_period_end', 'pending_plan_id', 'pending_billing_cycle', 'trial_used_at', 'past_due_since'];

    protected $hidden = ['id', 'tenant_id', 'plan_id'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at' => 'datetime',
            'trial_used_at' => 'datetime',
            'past_due_since' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }
}
