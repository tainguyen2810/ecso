<?php

namespace App\Modules\Sales\Services;

use RuntimeException;

/** "Tin nhắn" could not open; the message is shown to the seller as is. */
class EzChatInboxException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
