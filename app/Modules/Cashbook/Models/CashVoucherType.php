<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of receipt or payment the business names itself (rent, ads, other income). counts_in_pnl is the default
 * copied onto each voucher; turning a type off hides it from new vouchers but keeps the old ones.
 *
 * @property string $direction
 * @property string $name
 * @property bool $counts_in_pnl
 * @property string|null $pnl_group
 * @property bool $is_active
 */
class CashVoucherType extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['counts_in_pnl' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return HasMany<CashVoucher, $this> */
    public function vouchers(): HasMany
    {
        return $this->hasMany(CashVoucher::class);
    }
}
