<?php

namespace App\Modules\Marketplace\Exceptions;

use RuntimeException;

/** A marketplace API call failed. Temporary failures are retried; permanent ones are not. */
class ConnectorException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $temporary = true, public readonly bool $authExpired = false)
    {
        parent::__construct($message);
    }
}
