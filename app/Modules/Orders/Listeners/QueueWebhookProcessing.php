<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Marketplace\Events\WebhookReceived;
use App\Modules\Orders\Jobs\ProcessWebhookEvent;

class QueueWebhookProcessing
{
    public function handle(WebhookReceived $event): void
    {
        ProcessWebhookEvent::dispatch($event->webhookEventId)->afterCommit();
    }
}
