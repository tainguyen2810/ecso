<?php

namespace App\Modules\Marketplace\Connectors\EzChat;

use App\Modules\Marketplace\Connectors\ArrangesShipment;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\Data\ShopAuthorization;
use App\Modules\Marketplace\Connectors\FetchesSettlement;
use App\Modules\Marketplace\Connectors\FetchesSingleProduct;
use App\Modules\Marketplace\Connectors\MarketplaceConnector;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Shared\Support\ReportRange;
use Carbon\CarbonImmutable;

/**
 * A marketplace reached through EzChat (see EzChatGateway). Connecting a shop = the seller allows one of the shops
 * already connected in EzChat; the share token stands in for the marketplace tokens and never expires on its own
 * (EzChat refreshes the real ones).
 */
abstract class GatewayConnector implements ArrangesShipment, FetchesSettlement, FetchesSingleProduct, MarketplaceConnector
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
            // "Chuẩn bị hàng" and the marketplace's shipping label (see ArrangesShipment).
            Capability::ORDERS_PACK, Capability::SHIPPING_LABEL,
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
            scopes: ['ezchat:'.(string) (((array) ($result['workspace'] ?? []))['id'] ?? '')],
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
        if ($action !== 'pack') {
            throw new UnsupportedCapability($this->marketplace(), Capability::ORDER_ACTIONS[$action] ?? $action, Capability::UNKNOWN);
        }
        $method = (string) ($params['method'] ?? '');
        if (! in_array($method, ['pickup', 'dropoff'], true)) {
            throw new ConnectorException('Chọn cách giao hàng: shipper đến lấy hoặc tự mang ra bưu cục.', temporary: false);
        }
        if ($method === 'pickup' && blank($params['slot_id'] ?? null)) {
            throw new ConnectorException('Chọn khung giờ shipper đến lấy hàng.', temporary: false);
        }
        try {
            $this->arrange($account, $externalOrderId, $method, filled($params['address_id'] ?? null) ? (string) $params['address_id'] : null, filled($params['slot_id'] ?? null) ? (string) $params['slot_id'] : null);
        } catch (ConnectorException $e) {
            // EzChat prefixes the marketplace's words with the API path: the seller only needs the words.
            throw new ConnectorException($this->name().': '.preg_replace('#^/\S+: #', '', $e->getMessage()), $e->temporary);
        }
    }

    private function name(): string
    {
        return $this->marketplace() === 'tiktok' ? 'TikTok Shop' : 'Shopee';
    }

    /** Books the marketplace's carrier for the order ("Chuẩn bị hàng"). */
    abstract protected function arrange(MarketplaceAccount $account, string $externalOrderId, string $method, ?string $addressId, ?string $slotId): void;

    /** Label of a pickup slot in the shop's time: "Thứ 5 20/11 · 08:00–12:00". */
    protected static function slotLabel(int $start, ?int $end = null, ?string $text = null): string
    {
        $tz = ReportRange::timezone();
        $from = CarbonImmutable::createFromTimestamp($start, $tz);
        $day = ['CN', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][$from->dayOfWeek].' '.$from->format('d/m');
        $hours = filled($text) ? $text : ($end !== null ? $from->format('H:i').'–'.CarbonImmutable::createFromTimestamp($end, $tz)->format('H:i') : null);

        return $hours ? "{$day} · {$hours}" : $day;
    }

    /** Money as ECSO's decimal string (VND has no minor unit on either marketplace). */
    protected static function money(mixed $value): string
    {
        return is_numeric($value) ? (string) round((float) $value, 2) : '0';
    }
}
