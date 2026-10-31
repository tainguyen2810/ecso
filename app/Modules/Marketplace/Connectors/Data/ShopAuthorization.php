<?php

namespace App\Modules\Marketplace\Connectors\Data;

use Carbon\CarbonImmutable;

/** Result of a completed seller authorization or token refresh. Tokens are stored encrypted, never logged. */
final class ShopAuthorization
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $externalShopId,
        public readonly string $shopName,
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?CarbonImmutable $accessExpiresAt = null,
        public readonly ?CarbonImmutable $refreshExpiresAt = null,
        public readonly string $region = 'VN',
        public readonly array $scopes = [],
    ) {}
}
