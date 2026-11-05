<?php

namespace App\Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * A marketplace API call failed. Temporary failures are retried; permanent ones are not.
 * $errorCode is a SyncErrorCode when the connector knows it precisely (e.g. HTTP 429 = rate_limited); otherwise it is
 * derived from the flags.
 */
class ConnectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $temporary = true,
        public readonly bool $authExpired = false,
        public readonly bool $notFound = false,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
