<?php

namespace App\Modules\Invoicing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop asking us to connect an e-invoice provider we do not support yet (BKAV, EasyInvoice...).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $provider
 * @property string|null $note
 */
class EInvoiceProviderRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'einvoice_provider_requests';

    protected $guarded = ['id', 'tenant_id'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
