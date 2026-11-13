<?php

namespace App\Modules\Invoicing\Providers;

use App\Modules\Invoicing\Models\EInvoiceAccount;

/**
 * One e-invoice provider's API, used with the shop's own account. Every method throws EInvoiceException with a
 * message the shop can read. Nothing here touches orders (EInvoiceService does).
 */
interface EInvoiceProvider
{
    public function key(): string;

    public function label(): string;

    /** Fields the shop types to connect: name => label (secret fields end in "secret" or "password"). */
    public function credentialFields(): array;

    /**
     * Checks the credentials and lists the invoice templates the account may use.
     *
     * @return array{account: string, provider_name: string|null, templates: array<int, array{template_code: string, invoice_series: string, label: string}>}
     */
    public function connect(array $credentials, string $environment): array;

    /**
     * Sends one invoice to be issued now. Returns the provider's tracking code.
     *
     * @param  array<string, mixed>  $invoice  built by InvoicePayload
     */
    public function submit(EInvoiceAccount $account, array $invoice): string;

    public function check(EInvoiceAccount $account, string $trackingCode): EInvoiceResult;

    /** The invoice made with our reference code, if the provider has one (before asking again after a failure). */
    public function find(EInvoiceAccount $account, string $referenceCode): ?EInvoiceResult;
}
