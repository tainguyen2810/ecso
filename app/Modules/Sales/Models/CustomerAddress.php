<?php

namespace App\Modules\Sales\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * An extra delivery address of a customer (the main one stays on the customer). Phone and address are encrypted.
 * street/province_code/ward_code hold the standard parts when the address was picked from the province/ward lists.
 */
class CustomerAddress extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $fillable = ['label', 'recipient', 'phone', 'address'];

    protected $hidden = ['id', 'tenant_id', 'customer_id'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'address' => 'encrypted', 'street' => 'encrypted'];
    }
}
