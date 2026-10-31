<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Connectors\Data\OrderData;
use App\Modules\Marketplace\Connectors\Data\OrderPage;
use App\Modules\Marketplace\Connectors\Data\ProductPage;
use App\Modules\Marketplace\Connectors\Data\StockUpdate;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Connectors\Data\WebhookMessage;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;

/**
 * One implementation per marketplace (ARCHITECTURE §connector). Methods the marketplace does not offer, or that are
 * not verified yet, throw UnsupportedCapability; they never pretend to succeed.
 */
interface MarketplaceConnector
{
    public function marketplace(): string;

    /** @return array<string, string> capability => Capability::SUPPORTED|UNSUPPORTED|UNKNOWN */
    public function capabilities(): array;

    /** Checks the signature (and timestamp, when the marketplace sends one) of a raw webhook request. */
    public function verifyWebhook(string $body, array $headers): bool;

    /** @throws UnsupportedCapability */
    public function parseWebhook(string $body): WebhookMessage;

    /** @throws UnsupportedCapability|ConnectorException */
    public function getOrder(MarketplaceAccount $account, string $externalOrderId): OrderData;

    /** @throws UnsupportedCapability|ConnectorException */
    public function listOrders(MarketplaceAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?string $cursor = null): OrderPage;

    /** @throws UnsupportedCapability|ConnectorException */
    public function listProducts(MarketplaceAccount $account, ?string $cursor = null): ProductPage;

    /**
     * @param  array<int, StockUpdate>  $updates
     * @return array<int, StockUpdateResult>
     *
     * @throws UnsupportedCapability|ConnectorException
     */
    public function updateInventory(MarketplaceAccount $account, array $updates): array;

    /**
     * Order actions: confirm, pack, handover, cancel (see Capability::ORDER_ACTIONS).
     *
     * @param  array<string, mixed>  $params
     *
     * @throws UnsupportedCapability|ConnectorException
     */
    public function performOrderAction(MarketplaceAccount $account, string $externalOrderId, string $action, array $params = []): void;
}
