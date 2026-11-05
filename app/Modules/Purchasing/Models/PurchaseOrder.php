<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expected_at
 * @property Carbon|null $invoice_date
 * @property array<int, string>|null $tags
 * @property Carbon|null $ordered_at
 * @property Carbon|null $received_at
 * @property Carbon|null $closed_at
 * @property bool $extra_cost_to_supplier
 * @property bool $vat_in_cost
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const DRAFT = 'draft';

    public const ORDERED = 'ordered';

    public const PARTIAL = 'partial';

    public const RECEIVED = 'received';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::ORDERED, self::PARTIAL, self::RECEIVED, self::CLOSED, self::CANCELLED];

    /** Statuses in which goods can still arrive. */
    public const OPEN = [self::DRAFT, self::ORDERED, self::PARTIAL];

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'supplier_id', 'warehouse_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'expected_at' => 'date',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'tags' => 'array',
            'discount' => 'decimal:2',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'closed_at' => 'datetime',
            'total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'extra_cost' => 'decimal:2',
            'extra_cost_to_supplier' => 'boolean',
            'vat_in_cost' => 'boolean',
            'received_tax' => 'decimal:2',
            'received_extra_cost' => 'decimal:2',
            'received_value' => 'decimal:2',
            'returned_value' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    /** What is still owed to the supplier for this order. */
    public function balanceDue(): string
    {
        return bcsub(bcsub((string) $this->received_value, (string) $this->returned_value, 2), (string) $this->paid_amount, 2);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    /** @return HasMany<StockReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class)->orderBy('id');
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('id');
    }

    /** @return HasMany<SupplierReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class)->orderBy('id');
    }
}
