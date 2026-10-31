<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class RedactSecretsProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: RedactSecrets::redact($record->context), extra: RedactSecrets::redact($record->extra));
    }
}
