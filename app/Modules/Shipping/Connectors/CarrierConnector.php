<?php

namespace App\Modules\Shipping\Connectors;

use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Connectors\Data\AccountInfo;
use App\Modules\Shipping\Connectors\Data\CarrierEvent;
use App\Modules\Shipping\Connectors\Data\CreatedShipment;
use App\Modules\Shipping\Connectors\Data\ServiceQuote;
use App\Modules\Shipping\Connectors\Data\ShipmentDraft;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Request;

/**
 * One carrier's API, used with the shop's own account. Every method throws CarrierException with a message the
 * shop can read; nothing here touches orders or stock (CarrierShipmentService and CarrierEventApplier do).
 */
interface CarrierConnector
{
    /** Short key stored on accounts and shipments: ghn, viettelpost, fake... */
    public function key(): string;

    public function label(): string;

    /** Fields the shop types to connect: name => label (password-type fields end in "password" or "token"). */
    public function credentialFields(): array;

    /** Checks the credentials and returns who they belong to. */
    public function verify(array $credentials, string $environment): AccountInfo;

    /** @return array<int, ServiceQuote> cheapest first */
    public function quote(CarrierAccount $account, array $pickup, ShipmentDraft $draft): array;

    public function create(CarrierAccount $account, array $pickup, ShipmentDraft $draft, string $service, string $clientCode): CreatedShipment;

    /** The parcel made with our code, if the carrier has it (after a timeout). */
    public function findByClientCode(CarrierAccount $account, string $clientCode): ?CreatedShipment;

    public function cancel(CarrierAccount $account, Shipment $shipment): void;

    /** @param  array<int, Shipment>  $shipments  @return string URL of the printable labels */
    public function labelUrl(CarrierAccount $account, array $shipments, string $size): string;

    /** @return array<int, CarrierEvent> the parcel's history as the carrier has it now */
    public function track(CarrierAccount $account, Shipment $shipment): array;

    /** @return array<int, CarrierEvent> */
    public function parseWebhook(CarrierAccount $account, Request $request): array;
}
