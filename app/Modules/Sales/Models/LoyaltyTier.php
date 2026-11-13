<?php

namespace App\Modules\Sales\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer tier (C5), reached by spending at least min_spent over the last 12 months. Points earned are multiplied
 * by point_multiplier; the linked customer group gives the tier its price policy and discount.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $min_spent
 * @property string $point_multiplier
 * @property int|null $customer_group_id
 * @property string|null $color
 * @property-read CustomerGroup|null $customerGroup
 * @property-read int|null $customers_count
 */
class LoyaltyTier extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['name', 'min_spent', 'point_multiplier', 'customer_group_id', 'color'];

    protected function casts(): array
    {
        return ['min_spent' => 'decimal:2', 'point_multiplier' => 'decimal:2'];
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
