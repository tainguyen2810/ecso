<?php

namespace App\Modules\Invoicing\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An e-invoice asked for one order. `pending` until the provider confirms it was issued (or failed); a failed one
 * may be asked again with the same reference code, never a second issued invoice for the same order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $ulid
 * @property int $order_id
 * @property int $einvoice_account_id
 * @property string $provider
 * @property string $reference_code
 * @property string|null $tracking_code
 * @property string $status
 * @property string|null $template_code
 * @property string|null $invoice_series
 * @property string|null $invoice_number
 * @property Carbon|null $issued_at
 * @property string|null $pdf_url
 * @property string|null $xml_url
 * @property array<string, mixed>|null $buyer
 * @property string $total
 * @property string|null $message
 * @property int $checks
 * @property int|null $created_by
 */
class EInvoice extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const PENDING = 'pending';

    public const ISSUED = 'issued';

    public const FAILED = 'failed';

    protected $table = 'einvoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['buyer' => 'encrypted:array', 'issued_at' => 'datetime', 'total' => 'decimal:2', 'checks' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<EInvoiceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EInvoiceAccount::class, 'einvoice_account_id');
    }
}
