<?php

namespace App\Modules\Cashbook\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file kept with a voucher (CashVoucherAttachmentController).
 *
 * @property int $cash_voucher_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property Carbon|null $removed_at
 */
class CashVoucherAttachment extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const DISK = 'local';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'cash_voucher_id', 'path', 'uploaded_by', 'removed_by'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime', 'size' => 'integer'];
    }

    /** @return BelongsTo<CashVoucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class, 'cash_voucher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
