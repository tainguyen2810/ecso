<?php

namespace App\Modules\Marketplace\Connectors\Tiki;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\Data\ShopAuthorization;
use App\Modules\Marketplace\Connectors\UnverifiedConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;

/**
 * Tiki Open Platform, connected with the shop's own In-house app (C8): the owner creates the app on open.tiki.vn,
 * links it to the shop in Seller Center and pastes its Client ID and Client Secret (TikiConnectService).
 *
 * Verified from the public guides: the client-credentials token and GET sellers/me (shop id and name). Orders,
 * products and stock: the public guides name the endpoints (GET orders, the in-house event queue) but the request
 * and response fields sit in the reference behind the seller console, so those stay UNKNOWN and throw instead of
 * pretending. See docs/API-CAPABILITY-MATRIX.md §3.5.
 */
class TikiConnector extends UnverifiedConnector
{
    public const MARKETPLACE = 'tiki';

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }

    public function capabilities(): array
    {
        return array_merge(parent::capabilities(), [
            Capability::SHOP_AUTHORIZE => Capability::SUPPORTED,
            Capability::TOKEN_REFRESH => Capability::SUPPORTED,
        ]);
    }

    /** No refresh token on Tiki: a new token is asked for with the app's own Client ID and Secret. */
    public function refreshCredentials(MarketplaceAccount $account): ShopAuthorization
    {
        $client = TikiClient::forAccount($account);
        $token = $client->requestToken();

        return new ShopAuthorization(
            externalShopId: (string) $account->external_shop_id,
            shopName: (string) $account->shop_name,
            accessToken: $token['access_token'],
            refreshToken: $account->credential?->refresh_token,
            accessExpiresAt: $token['expires_at'],
        );
    }
}
