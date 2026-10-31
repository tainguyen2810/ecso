<?php

namespace App\Modules\Marketplace\Connectors\Data;

/** What we need from a verified webhook: which shop, which event, which order. */
final class WebhookMessage
{
    public function __construct(
        public readonly ?string $shopId,
        public readonly string $type,
        public readonly ?string $eventId = null,
        public readonly ?string $orderId = null,
        public readonly ?int $timestamp = null,
    ) {}
}
