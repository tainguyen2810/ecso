<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Data\OrderPage;
use App\Modules\Marketplace\Connectors\Data\ProductPage;
use App\Modules\Marketplace\Connectors\Data\WebhookMessage;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;

/**
 * Base for a real marketplace whose API has not been verified against official documentation yet.
 * Every capability is UNKNOWN and every call throws UnsupportedCapability (state UNKNOWN), so nothing can
 * look like a working integration. A subclass overrides a method only once it is implemented and verified.
 */
abstract class UnverifiedConnector implements MarketplaceConnector
{
    public function capabilities(): array
    {
        return Capability::allUnknown();
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        // Unknown signature scheme: reject rather than accept unverified payloads.
        return false;
    }

    public function parseWebhook(string $body): WebhookMessage
    {
        throw $this->unknown(Capability::ORDERS_WEBHOOK);
    }

    public function getOrder(MarketplaceAccount $account, string $externalOrderId): OrderData
    {
        throw $this->unknown(Capability::ORDERS_FETCH);
    }

    public function listOrders(MarketplaceAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?string $cursor = null): OrderPage
    {
        throw $this->unknown(Capability::ORDERS_LIST);
    }

    public function listProducts(MarketplaceAccount $account, ?string $cursor = null): ProductPage
    {
        throw $this->unknown(Capability::PRODUCTS_LIST);
    }

    public function updateInventory(MarketplaceAccount $account, array $updates): array
    {
        throw $this->unknown(Capability::INVENTORY_UPDATE);
    }

    public function performOrderAction(MarketplaceAccount $account, string $externalOrderId, string $action, array $params = []): void
    {
        throw $this->unknown(Capability::ORDER_ACTIONS[$action] ?? $action);
    }

    protected function unknown(string $capability): UnsupportedCapability
    {
        return new UnsupportedCapability($this->marketplace(), $capability, $this->capabilities()[$capability] ?? Capability::UNKNOWN);
    }
}
