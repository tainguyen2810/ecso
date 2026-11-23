<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Hoá đơn mua vào": one e-invoice a supplier issued to the business. status: open (not in a purchase order yet),
 * linked (a purchase order was made from it), ignored ("Không đưa vào": an expense, a wrong or cancelled invoice).
 *
 * @property array<int, array<string, mixed>> $lines
 * @property array<int, string>|null $issues
 * @property Carbon|null $issued_on
 */
class PurchaseInvoice extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const STATUSES = ['open', 'linked', 'ignored'];

    /** Issues that make the VAT not deductible as it is (the page shows them in red). */
    public const BLOCKING = ['buyer_mismatch', 'no_authority_code'];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'supplier_id', 'purchase_order_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date', 'lines' => 'array', 'issues' => 'array',
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'shipping' => 'decimal:2',
            'vat_amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function blocked(): bool
    {
        return array_intersect($this->issues ?? [], self::BLOCKING) !== [];
    }

    public function reference(): string
    {
        return trim($this->series.' '.$this->number);
    }
}
