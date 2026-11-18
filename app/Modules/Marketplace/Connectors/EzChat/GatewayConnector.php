<?php

namespace App\Modules\Marketplace\Connectors\EzChat;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\Data\ShopAuthorization;
use App\Modules\Marketplace\Connectors\FetchesSingleProduct;
use App\Modules\Marketplace\Connectors\MarketplaceConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\MarketplaceAccount;

/**
 * A marketplace reached through EzChat (see EzChatGateway). Connecting a shop = the seller allows one of the shops
 * already connected in EzChat; the share token stands in for the marketplace tokens and never expires on its own
 * (EzChat refreshes the real ones).
 */
abstract class GatewayConnector implements FetchesSingleProduct, MarketplaceConnector
{
    public function __construct(protected readonly EzChatGateway $gateway) {}

    /** EzChat's channel type: SHOPEE or TIKTOK_SHOP. */
    abstract protected function ezchatType(): string;

    /** Webhook path EzChat posts pushes to (signed over it). */
    protected function webhookPath(): string
    {
        return '/api/v1/webhooks/'.$this->marketplace();
    }

    public function capabilities(): array
    {
        $supported = [
            Capability::SHOP_AUTHORIZE, Capability::ORDERS_WEBHOOK, Capability::ORDERS_FETCH, Capability::ORDERS_LIST,
            Capability::PRODUCTS_LIST, Capability::INVENTORY_UPDATE, Capability::INVENTORY_GET,
        ];

        return [
            ...Capability::allUnknown(),
            Capability::TOKEN_REFRESH => Capability::UNSUPPORTED,
            ...array_fill_keys($supported, Capability::SUPPORTED),
        ];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->gateway->connectUrl($this->ezchatType(), $state, $redirectUri);
    }

    public function completeAuthorization(array $query, string $redirectUri): ShopAuthorization
    {
        if (($query['error'] ?? '') !== '') {
            throw new ConnectorException('Đã hủy kết nối shop.', temporary: false);
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw new ConnectorException('Thiếu mã kết nối từ EzChat.', temporary: false);
        }
        $result = $this->gateway->exchange($code);
        $shop = (array) ($result['shop'] ?? []);
        if (($shop['type'] ?? null) !== $this->ezchatType()) {
            throw new ConnectorException('Shop được chọn không thuộc sàn này.', temporary: false);
        }

        return new ShopAuthorization(
            externalShopId: (string) $shop['shopId'],
            shopName: (string) ($shop['name'] ?? ''),
            accessToken: (string) $result['token'],
            region: (string) ($shop['region'] ?? 'VN'),
            scopes: ['ezchat:'.($result['workspace']['id'] ?? '')],
        );
    }

    public function refreshCredentials(MarketplaceAccount $account): ShopAuthorization
    {
        throw new UnsupportedCapability($this->marketplace(), Capability::TOKEN_REFRESH, Capability::UNSUPPORTED);
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        return $this->gateway->verifyPush($this->webhookPath(), $body, $headers);
    }

    public function performOrderAction(MarketplaceAccount $account, string $externalOrderId, string $action, array $params = []): void
    {
        throw new UnsupportedCapability($this->marketplace(), Capability::ORDER_ACTIONS[$action] ?? $action, Capability::UNKNOWN);
    }

    /** Money as ECSO's decimal string (VND has no minor unit on either marketplace). */
    protected static function money(mixed $value): string
    {
        return is_numeric($value) ? (string) round((float) $value, 2) : '0';
    }
}
