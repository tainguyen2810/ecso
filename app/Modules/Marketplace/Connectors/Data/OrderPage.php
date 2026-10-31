<?php

namespace App\Modules\Marketplace\Connectors\Data;

final class OrderPage
{
    /**
     * @param  array<int, string>  $orderIds  external order ids changed in the window
     */
    public function __construct(public readonly array $orderIds, public readonly ?string $nextCursor = null) {}
}
