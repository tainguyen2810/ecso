<?php

namespace Tests\Support;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\Data\ShopAuthorization;
use App\Modules\Marketplace\Connectors\UnverifiedConnector;
use Carbon\CarbonImmutable;

/**
 * Test double used ONLY to exercise the generic shop-authorization flow (state handling, tenant binding, token
 * storage). It is registered in config by the tests; it never runs outside the test suite.
 */
class AuthorizingTestConnector extends UnverifiedConnector
{
    public function marketplace(): string
    {
        return 'testmp';
    }

    public function capabilities(): array
    {
        return [...parent::capabilities(), Capability::SHOP_AUTHORIZE => Capability::SUPPORTED];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://auth.test/authorize?'.http_build_query(['state' => $state, 'redirect' => $redirectUri]);
    }

    public function completeAuthorization(array $query, string $redirectUri): ShopAuthorization
    {
        return new ShopAuthorization(
            externalShopId: $query['shop_id'],
            shopName: 'Shop '.$query['shop_id'],
            accessToken: 'access-'.$query['code'],
            refreshToken: 'refresh-'.$query['code'],
            accessExpiresAt: CarbonImmutable::now()->addHours(4),
        );
    }
}
