<?php

namespace App\Modules\Invoicing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The shop's account at an e-invoice provider (one per shop). Credentials are encrypted and never serialized.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $ulid
 * @property string $provider
 * @property string $environment
 * @property array<string, mixed>|null $credentials
 * @property string|null $external_account
 * @property string|null $provider_name
 * @property array<int, array{template_code: string, invoice_series: string, label: string}>|null $templates
 * @property string|null $template_code
 * @property string|null $invoice_series
 * @property string $issue_mode
 * @property bool $send_email
 * @property string $status
 * @property string|null $last_error
 * @property Carbon|null $last_verified_at
 */
class EInvoiceAccount extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const CONNECTED = 'connected';

    public const ERROR = 'error';

    public const SANDBOX = 'sandbox';

    public const PRODUCTION = 'production';

    /** When invoices are issued: by hand on each order, when an own-channel order is fully paid, when it is completed. */
    public const MODES = ['manual', 'paid', 'completed'];

    protected $table = 'einvoice_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'tenant_id', 'credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'templates' => 'array',
            'send_email' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === self::CONNECTED && $this->template_code !== null && $this->invoice_series !== null;
    }
}
