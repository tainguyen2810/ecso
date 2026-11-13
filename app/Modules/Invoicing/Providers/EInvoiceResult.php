<?php

namespace App\Modules\Invoicing\Providers;

/** Where an invoice request stands at the provider. */
final class EInvoiceResult
{
    public const PENDING = 'pending';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly ?string $message = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $issuedDate = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $xmlUrl = null,
        /** The provider's own status of the invoice: draft, issued... */
        public readonly ?string $invoiceStatus = null,
    ) {}
}
