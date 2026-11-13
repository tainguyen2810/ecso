<?php

namespace App\Modules\Messaging\Zalo;

use RuntimeException;

/** A Zalo call that did not work; the message is for the shop. authFailed: the connection must be made again. */
class ZaloException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $authFailed = false, int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
