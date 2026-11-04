<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One merge of a duplicate card into the card that was kept. The merged card is gone from the list but kept here in
 * full (encrypted), with what moved over, so a merge can always be traced and its data read back.
 *
 * @property array<string, mixed> $snapshot
 * @property array<string, int> $moved
 * @property array<string, string>|null $choices
 * @property Carbon|null $created_at
 */
class CustomerMerge extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'merged_customer_id', 'merged_code', 'merged_name', 'snapshot', 'moved', 'choices', 'merged_by'];

    protected $hidden = ['id', 'tenant_id', 'customer_id', 'merged_customer_id', 'merged_by', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'moved' => 'array', 'choices' => 'array'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
