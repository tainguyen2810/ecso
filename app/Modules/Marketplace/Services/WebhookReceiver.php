<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Events\WebhookReceived;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Step [1] of ORDER-LIFECYCLE §3: verify, store in the inbox, answer fast. Processing happens in a queued job.
 * Nothing in the payload is trusted to pick the tenant; that is resolved later from marketplace_accounts.
 */
class WebhookReceiver
{
    private const MAX_STORED_PAYLOAD = 65535;

    public function __construct(private readonly ConnectorRegistry $connectors) {}

    /**
     * @param  array<string, mixed>  $headers
     * @return array{accepted: bool, duplicate: bool, event_id: int|null}
     */
    public function receive(string $marketplace, string $body, array $headers): array
    {
        $connector = $this->connectors->for($marketplace);
        $hash = hash('sha256', $body);

        if (! $connector->verifyWebhook($body, $headers)) {
            // Kept for monitoring (one row per distinct body), never processed.
            WebhookEvent::query()->insertOrIgnore([
                'marketplace' => $marketplace,
                'dedupe_key' => "invalid:{$hash}",
                'payload_hash' => $hash,
                'signature_valid' => false,
                'status' => WebhookEvent::REJECTED,
                'result' => 'Chữ ký hoặc thời gian không hợp lệ',
                'received_at' => now(),
            ]);

            return ['accepted' => false, 'duplicate' => false, 'event_id' => null];
        }

        try {
            $message = $connector->parseWebhook($body);
        } catch (ConnectorException|UnsupportedCapability $e) {
            return ['accepted' => false, 'duplicate' => false, 'event_id' => null];
        }

        $dedupe = $message->eventId !== null ? "id:{$message->eventId}" : "hash:{$hash}";

        try {
            $event = WebhookEvent::query()->create([
                'marketplace' => $marketplace,
                'dedupe_key' => $dedupe,
                'event_type' => $message->type,
                'external_event_id' => $message->eventId,
                'external_shop_id' => $message->shopId,
                'external_order_id' => $message->orderId,
                'payload_hash' => $hash,
                'signature_valid' => true,
                'payload' => strlen($body) <= self::MAX_STORED_PAYLOAD ? json_decode($body, true) : null,
                'status' => WebhookEvent::RECEIVED,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = WebhookEvent::query()->where('marketplace', $marketplace)->where('dedupe_key', $dedupe)->value('id');

            return ['accepted' => true, 'duplicate' => true, 'event_id' => $existing];
        }

        WebhookReceived::dispatch($event->id);

        return ['accepted' => true, 'duplicate' => false, 'event_id' => $event->id];
    }
}
