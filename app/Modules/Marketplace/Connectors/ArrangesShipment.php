<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Models\MarketplaceAccount;

/**
 * "Chuẩn bị hàng" on a marketplace: the seller tells the marketplace how the parcel reaches its carrier (picked up at
 * an address in a time slot, or dropped off), the marketplace books the carrier and issues the tracking number and the
 * shipping label. performOrderAction('pack', ShipChoice) does the booking.
 */
interface ArrangesShipment
{
    /**
     * How this order can be handed to the carrier now.
     *
     * @return array{pickup: array{available: bool, addresses: list<array{id: string, label: string, slots: list<array{id: string, label: string}>}>}, dropoff: array{available: bool}, carrier: string|null}
     */
    public function shipOptions(MarketplaceAccount $account, string $externalOrderId): array;

    /** The marketplace's shipping label of an arranged order, as PDF bytes. */
    public function shippingLabel(MarketplaceAccount $account, string $externalOrderId): string;
}
