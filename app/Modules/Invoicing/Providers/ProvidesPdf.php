<?php

namespace App\Modules\Invoicing\Providers;

use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Models\EInvoiceAccount;

/** A provider that gives the issued invoice's PDF only through its API (no public link): we serve it ourselves. */
interface ProvidesPdf
{
    /** The PDF file's bytes. Throws EInvoiceException with a message the shop can read. */
    public function pdf(EInvoiceAccount $account, EInvoice $invoice): string;
}
