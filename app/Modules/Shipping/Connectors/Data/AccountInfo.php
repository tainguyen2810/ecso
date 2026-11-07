<?php

namespace App\Modules\Shipping\Connectors\Data;

/** Who the credentials belong to at the carrier, and the pickup points (cửa hàng / kho lấy hàng) it knows. */
final class AccountInfo
{
    /**
     * @param  array<int, array{id: string, name: string, address?: string|null, province_code?: string|null}>  $locations
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $externalAccount,
        public readonly array $locations,
    ) {}
}
