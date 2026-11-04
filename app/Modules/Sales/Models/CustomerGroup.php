<?php

namespace App\Modules\Sales\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of customers with the terms they buy on: the product price an order starts from (retail or wholesale),
 * a discount and a default payment method.
 *
 * @property-read int|string|null $customers_count
 */
class CustomerGroup extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const PRICE_TIERS = ['retail', 'wholesale'];

    protected $fillable = ['code', 'name', 'description', 'price_tier', 'discount_percent', 'payment_method', 'payment_terms_days', 'credit_limit', 'is_active', 'auto_min_spent', 'auto_min_orders'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2', 'credit_limit' => 'decimal:2', 'is_active' => 'boolean', 'auto_min_spent' => 'decimal:2'];
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
