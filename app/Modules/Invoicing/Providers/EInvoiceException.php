<?php

namespace App\Modules\Invoicing\Providers;

use RuntimeException;

/**
 * A provider refused or did not answer. The message is safe to show the shop. `uncertain`: the request may have
 * reached the provider (timeout on a call that issues), so it must be checked before asking again.
 */
class EInvoiceException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false, public readonly bool $authFailed = false)
    {
        parent::__construct($message);
    }
}
