<?php

namespace App\Modules\Invoicing\Models;

use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * An e-invoice package the platform sells as a reseller (set in /admin › Bán hóa đơn). Not per tenant.
 *
 * @property int $id
 * @property string $ulid
 * @property string $provider
 * @property string $name
 * @property int $invoices
 * @property string $price
 * @property string $setup_fee
 * @property string|null $signing_price
 * @property string|null $note
 * @property bool $active
 * @property bool $featured
 * @property int $sort
 */
class EInvoicePackage extends Model
{
    use HasPublicUlid;

    protected $table = 'einvoice_packages';

    protected $guarded = ['id', 'ulid'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'setup_fee' => 'decimal:2', 'signing_price' => 'decimal:2', 'active' => 'boolean', 'featured' => 'boolean', 'invoices' => 'integer', 'sort' => 'integer'];
    }
}
