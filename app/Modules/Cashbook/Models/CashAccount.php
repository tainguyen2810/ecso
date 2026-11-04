<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A fund (quỹ): the cash drawer, a bank account, an e-wallet, COD held by carriers. `methods` lists the payment
 * methods whose money lands here unless another fund is picked; each method has at most one such fund. Funds are
 * turned off, never deleted, so their history stays.
 *
 * @property string $kind
 * @property string $name
 * @property array<int, string> $methods
 * @property string $opening_balance
 * @property bool $is_active
 * @property Carbon|null $opening_at
 */
class CashAccount extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const KINDS = ['cash', 'bank', 'ewallet', 'cod', 'other'];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['methods' => 'array', 'opening_balance' => 'decimal:2', 'opening_at' => 'datetime', 'is_active' => 'boolean'];
    }
}
