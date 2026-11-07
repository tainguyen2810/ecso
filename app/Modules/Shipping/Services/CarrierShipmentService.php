<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Orders\Services\ShipmentService;
use App\Modules\Orders\Services\ShipmentSync;
use App\Modules\Orders\Support\Tracking;
use App\Modules\Sales\Services\ManualOrderService;
use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Connectors\CarrierRegistry;
use App\Modules\Shipping\Connectors\Data\CreatedShipment;
use App\Modules\Shipping\Connectors\Data\ShipmentDraft;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Đẩy qua hãng vận chuyển": makes the parcel at the carrier with the shop's own account, then keeps the shipment
 * and the order in step. The shipment row is written before the carrier is called, with our own code (client_code)
 * sent along, so a lost answer is looked up instead of making a second parcel; a refused parcel stays "Chờ đẩy"
 * with the carrier's reason and can be pushed again.
 */
class CarrierShipmentService
{
    /** Order statuses a parcel can still be made for: goods not handed over yet. */
    public const PUSHABLE = [OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK, OrderStatus::PACKED];

    public function __construct(
        private readonly CarrierRegistry $carriers,
        private readonly ShipmentSync $sync,
        private readonly ShipmentService $shipments,
        private readonly ManualOrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws ValidationException with the carrier's reason; the shipment then keeps it as api_error */
    public function push(Order $order, CarrierAccount $account, string $service, ?string $serviceName, ShipmentDraft $draft): Shipment
    {
        if (! $account->isUsable() || ! $this->carriers->has($account->carrier)) {
            throw ValidationException::withMessages(['account_id' => 'Kết nối hãng này đang lỗi hoặc đã ngắt. Kiểm tra lại ở Kết nối vận chuyển.']);
        }
        $connector = $this->carriers->get($account->carrier);
        $pickup = $account->pickupFor($draft->warehouseId)
            ?? throw ValidationException::withMessages(['account_id' => 'Chưa chọn điểm lấy hàng của hãng cho kho này.']);
        $shipment = $this->open($order, $account, $connector->label(), $service, $serviceName, $draft);

        try {
            $created = $connector->create($account, $pickup, $draft, $service, (string) $shipment->client_code);
        } catch (CarrierException $e) {
            $created = $e->uncertain ? $this->lookUp($account, $shipment) : null;
            if ($created === null) {
                $this->fail($shipment, $e->getMessage());
                throw ValidationException::withMessages(['carrier' => $e->getMessage()]);
            }
        }

        return $this->confirm($order, $shipment, $created, $connector->label());
    }

    /**
     * The carrier reports a parcel for a push whose answer was lost (it carries our code): the parcel exists, so the
     * shipment takes its number as if the push had answered.
     */
    public function adopt(Shipment $shipment, string $trackingNumber): Shipment
    {
        $account = $this->accountOf($shipment);
        $order = Order::query()->findOrFail($shipment->order_id);

        return $this->confirm($order, $shipment, new CreatedShipment($trackingNumber, null, null, $trackingNumber), $this->carriers->get($account->carrier)->label());
    }

    /** "Hủy vận đơn": at the carrier first (when it was made there), then here. */
    public function cancel(Shipment $shipment): Shipment
    {
        $account = $this->accountOf($shipment);
        if ($shipment->tracking_number !== null && $shipment->status !== ShipmentStatus::CANCELLED) {
            try {
                $this->carriers->get($account->carrier)->cancel($account, $shipment);
            } catch (CarrierException $e) {
                throw ValidationException::withMessages(['carrier' => $e->getMessage()]);
            }
        }

        return $this->shipments->act($shipment, 'cancel', 'Hủy vận đơn trên '.$this->carriers->get($account->carrier)->label());
    }

    /**
     * Printable labels of shipments made at one carrier account.
     *
     * @param  array<int, Shipment>  $shipments
     */
    public function labelUrl(array $shipments, string $size): string
    {
        $accounts = collect($shipments)->pluck('carrier_account_id')->unique();
        if ($accounts->count() !== 1 || $accounts->first() === null) {
            throw ValidationException::withMessages(['ids' => 'Chọn các vận đơn đã đẩy qua cùng một tài khoản hãng.']);
        }
        if (collect($shipments)->contains(fn (Shipment $s) => $s->tracking_number === null || $s->status === ShipmentStatus::CANCELLED)) {
            throw ValidationException::withMessages(['ids' => 'Có vận đơn chưa tạo xong hoặc đã hủy.']);
        }
        $account = $this->accountOf($shipments[0]);
        try {
            return $this->carriers->get($account->carrier)->labelUrl($account, $shipments, $size);
        } catch (CarrierException $e) {
            throw ValidationException::withMessages(['ids' => $e->getMessage()]);
        }
    }

    /** "Bỏ": gives up a parcel the carrier refused, so the order can be pushed again or shipped another way. */
    public function discard(Shipment $shipment): Shipment
    {
        if ($shipment->source !== Shipment::SOURCE_API || $shipment->status !== ShipmentStatus::PENDING || $shipment->tracking_number !== null) {
            throw ValidationException::withMessages(['shipment' => 'Chỉ bỏ được vận đơn chưa tạo được trên hãng.']);
        }

        return $this->shipments->act($shipment, 'cancel', 'Bỏ vận đơn chưa tạo được');
    }

    private function open(Order $order, CarrierAccount $account, string $label, string $service, ?string $serviceName, ShipmentDraft $draft): Shipment
    {
        if (! ShipmentService::shipsItself($order)) {
            throw ValidationException::withMessages(['order' => 'Đơn sàn do sàn tạo vận đơn.']);
        }

        return DB::transaction(function () use ($order, $account, $label, $service, $serviceName, $draft) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, self::PUSHABLE, true)) {
                throw ValidationException::withMessages(['order' => 'Đơn đã giao cho hãng, đã hủy hoặc chưa thanh toán: không đẩy được.']);
            }
            $main = $this->sync->main($order);
            $retry = $main !== null && $main->source === Shipment::SOURCE_API && $main->status === ShipmentStatus::PENDING && $main->tracking_number === null;
            if ($main !== null && ! $retry) {
                throw ValidationException::withMessages(['order' => 'Đơn đã có vận đơn. Hủy vận đơn cũ trước khi đẩy lại.']);
            }
            if ($retry && $main->carrier_account_id !== $account->id) {
                // Another carrier this time: the old code was never used there, so start a fresh one.
                $main->client_code = null;
            }

            $order->forceFill(['shipping_carrier' => $label])->save();
            $shipment = $retry ? $main : new Shipment(['order_id' => $order->id, 'status' => ShipmentStatus::PENDING, 'created_by' => Auth::id()]);
            $shipment->fill([
                'source' => Shipment::SOURCE_API,
                'carrier_account_id' => $account->id,
                'carrier' => $account->carrier,
                'carrier_name' => $label,
                'client_code' => $shipment->client_code ?? (string) Str::ulid(),
                'service' => mb_substr($service, 0, 50),
                'service_name' => $serviceName !== null ? mb_substr($serviceName, 0, 100) : null,
                'warehouse_id' => $draft->warehouseId ?? $order->warehouse_id,
                'ship_by_at' => $order->ship_by_at,
                'packages' => 1,
                'weight_g' => $draft->weightG,
                'length_cm' => $draft->lengthCm,
                'width_cm' => $draft->widthCm,
                'height_cm' => $draft->heightCm,
                'payer' => $draft->payer,
                'api_options' => $draft->options(),
                'api_error' => null,
            ])->save();
            // The amount to collect follows the order until pickup, except when the shop typed another one.
            $shipment->forceFill(['cod_amount' => $draft->codAmount, 'cod_status' => bccomp($draft->codAmount, '0', 2) > 0 ? Shipment::COD_PENDING : Shipment::COD_NONE])->save();

            return $shipment;
        });
    }

    private function lookUp(CarrierAccount $account, Shipment $shipment): ?CreatedShipment
    {
        try {
            return $this->carriers->get($account->carrier)->findByClientCode($account, (string) $shipment->client_code);
        } catch (CarrierException) {
            return null;
        }
    }

    private function fail(Shipment $shipment, string $reason): void
    {
        $shipment->forceFill(['api_error' => mb_substr($reason, 0, 255), 'last_event_at' => now()])->save();
        $this->sync->event($shipment, ShipmentStatus::PENDING, now(), 'Hãng từ chối: '.$reason, 'push_failed:'.now()->getTimestamp(), ShipmentEvent::SOURCE_API);
    }

    private function confirm(Order $order, Shipment $shipment, CreatedShipment $created, string $label): Shipment
    {
        // Pushing to the carrier is the shop's confirmation of a new order.
        if ($order->isManual() && $order->refresh()->status === OrderStatus::PENDING_CONFIRMATION) {
            $this->orders->transition($order, 'confirm');
        }

        return DB::transaction(function () use ($order, $shipment, $created, $label) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $tracking = Tracking::normalize($created->trackingNumber);
            Tracking::assertUnique($order, $tracking);
            $order->forceFill(['shipping_carrier' => $label, 'tracking_number' => $tracking])->save();

            $shipment->forceFill([
                'tracking_number' => $tracking,
                'external_id' => $created->externalId,
                'fee_estimated' => $created->fee,
                'fee_status' => $created->fee !== null ? 'estimated' : 'unknown',
                'expected_delivery_at' => $created->expectedAt,
                'status' => ShipmentStatus::AWAITING_PICKUP,
                'last_event_at' => now(),
                'api_error' => null,
            ])->save();
            $this->sync->event($shipment, ShipmentStatus::AWAITING_PICKUP, now(), "Đã đẩy qua {$label}: {$tracking}", 'pushed:'.$tracking, ShipmentEvent::SOURCE_API);
            $this->sync->apply($order);
            $this->audit->record('shipment.pushed', $order, ['order' => $order->external_order_id, 'carrier' => $shipment->carrier, 'tracking_number' => $tracking, 'fee' => $created->fee]);

            return $shipment->refresh();
        });
    }

    private function accountOf(Shipment $shipment): CarrierAccount
    {
        $account = $shipment->carrier_account_id !== null ? CarrierAccount::query()->find($shipment->carrier_account_id) : null;
        if ($account === null || $shipment->source !== Shipment::SOURCE_API) {
            throw ValidationException::withMessages(['shipment' => 'Vận đơn này không tạo qua kết nối hãng.']);
        }
        if (! $this->carriers->has($account->carrier) || $account->credentials === null) {
            throw ValidationException::withMessages(['shipment' => 'Kết nối hãng đã ngắt. Kết nối lại để thao tác trên hãng.']);
        }

        return $account;
    }
}
