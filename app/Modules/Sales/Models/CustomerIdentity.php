<?php

namespace App\Modules\Sales\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The id a buyer has on one connected store (a WooCommerce customer id, a marketplace buyer id). It is the surest
 * way to recognise the same buyer on their next order, before the phone and the email.
 *
 * @property Carbon|null $last_seen_at
 */
class CustomerIdentity extends Model
{
    use BelongsToTenant;

    protected $fillable = ['customer_id', 'source', 'marketplace_account_id', 'external_id', 'label', 'last_seen_at'];

    protected $hidden = ['id', 'tenant_id', 'customer_id', 'marketplace_account_id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
