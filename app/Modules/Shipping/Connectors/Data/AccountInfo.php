<?php

namespace App\Modules\Shipping\Connectors\Data;

/** Who the credentials belong to at the carrier, and the pickup points (cửa hàng / kho lấy hàng) it knows. */
final class AccountInfo
{
    /**
     * @param  array<int, array{id: string, name: string, address?: string|null, province_code?: string|null}>  $locations
     * @param  array<string, string>|null  $credentials  what to keep instead of what the shop typed (e.g. a long-lived
     *                                                   token in place of a password); null = keep what was typed
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $externalAccount,
        public readonly array $locations,
        public readonly ?array $credentials = null,
    ) {}
}
