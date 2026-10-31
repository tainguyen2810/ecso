<?php

namespace App\Modules\Marketplace\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A verified, new webhook was stored in the inbox and waits for processing. */
class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly int $webhookEventId) {}
}
