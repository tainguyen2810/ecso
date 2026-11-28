<?php

namespace App\Modules\Invoicing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shop's purchase of an e-invoice package and its registration, step by step. Paid by bank transfer with the
 * transfer code, confirmed by a platform admin; the later steps are moved by the admin as the provider answers.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $ulid
 * @property int|null $package_id
 * @property string $number
 * @property string $provider
 * @property string $package_name
 * @property int $invoices
 * @property string $price
 * @property string $setup_fee
 * @property bool $signing
 * @property string $signing_price
 * @property string $amount
 * @property string $transfer_code
 * @property string $status
 * @property array<string, mixed> $company
 * @property string|null $shop_note
 * @property string|null $sign_url
 * @property array<string, string>|null $steps
 * @property Carbon|null $paid_at
 * @property string|null $payment_ref
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 */
class EInvoiceOrder extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const PAID = 'paid';

    public const ACCOUNT_CREATED = 'account_created';

    public const WAITING_SIGNATURE = 'waiting_signature';

    public const TAX_APPROVED = 'tax_approved';

    public const CONNECTED = 'connected';

    public const CANCELLED = 'cancelled';

    /** The steps in order, as the shop sees them. */
    public const STEPS = [self::AWAITING_PAYMENT, self::PAID, self::ACCOUNT_CREATED, self::WAITING_SIGNATURE, self::TAX_APPROVED, self::CONNECTED];

    protected $table = 'einvoice_orders';

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'package_id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'setup_fee' => 'decimal:2', 'signing_price' => 'decimal:2', 'amount' => 'decimal:2',
            'signing' => 'boolean', 'company' => 'array', 'steps' => 'array', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'invoices' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::CONNECTED, self::CANCELLED], true);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
