<?php

namespace App\Modules\Assistant\Services;

use RuntimeException;

/** AI could not run; the message is for the user, reason is a short code for the page. */
class AssistantException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
