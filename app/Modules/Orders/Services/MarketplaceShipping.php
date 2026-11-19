<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Marketplace\Connectors\ArrangesShipment;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Chuẩn bị hàng" and "In phiếu sàn" for marketplaces that do it through their API (Shopee, TikTok Shop through
 * EzChat). A choice is {method: pickup|dropoff, address_id, slot_id} plus the labels shown to the seller; the last one
 * per shop is remembered and offered first next time.
 */
class MarketplaceShipping
{
    /** Statuses where the marketplace has booked the carrier, so a label exists. */
    public const LABEL_FROM = [OrderStatus::PACKED, OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT, OrderStatus::FAILED_DELIVERY];

    public const MAX_LABELS = 50;

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Marketplaces whose connector arranges shipment (by configuration, without building connectors).
     *
     * @return list<string>
     */
    public static function marketplaces(): array
    {
        return array_keys(array_filter((array) config('marketplaces.connectors'), fn ($class) => is_string($class) && is_subclass_of($class, ArrangesShipment::class)));
    }

    /** The connector when this order's shop arranges shipment through the marketplace API. */
    public function connector(Order $order): ?ArrangesShipment
    {
        if ($order->isManual() || $order->account === null) {
            return null;
        }
        try {
            $connector = $this->connectors->for($order->account->marketplace);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $connector instanceof ArrangesShipment ? $connector : null;
    }

    /**
     * What the "Chuẩn bị hàng" dialog shows: one group per shop with the ways to hand over, and the orders it skips.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{groups: list<array<string, mixed>>, skipped: list<array{id: string, external_order_id: string, message: string}>}
     */
    public function options(Collection $orders): array
    {
        $groups = [];
        $skipped = [];
        foreach ($orders->groupBy(fn (Order $o) => $o->marketplace_account_id ?? 'own') as $list) {
            /** @var Order $first */
            $first = $list->first();
            $connector = $this->connector($first);
            foreach ($list as $order) {
                $reason = match (true) {
                    $connector === null => 'Đơn này không chuẩn bị hàng qua sàn được.',
                    $order->status !== OrderStatus::READY_TO_PACK => 'Đơn không ở bước Chờ đóng gói.',
                    default => null,
                };
                if ($reason !== null) {
                    $skipped[] = ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'message' => $reason];
                }
            }
            $ready = $list->filter(fn (Order $o) => $connector !== null && $o->status === OrderStatus::READY_TO_PACK)->values();
            if ($ready->isEmpty()) {
                continue;
            }
            $account = $first->account;
            try {
                $options = $connector->shipOptions($account, $ready->first()->external_order_id);
                $error = null;
            } catch (ConnectorException $e) {
                $options = null;
                $error = $e->getMessage();
            }
            $groups[] = [
                'account' => ['id' => $account->ulid, 'name' => $account->shop_name, 'marketplace' => $account->marketplace],
                'orders' => $ready->map(fn (Order $o) => ['id' => $o->ulid, 'external_order_id' => $o->external_order_id])->all(),
                'options' => $options,
                'error' => $error,
                'last' => $account->settings['ship_choice'] ?? null,
            ];
        }

        return ['groups' => $groups, 'skipped' => $skipped];
    }

    /**
     * The choice sent with a "pack" action for this order's shop: params.shipping is keyed by shop id.
     *
     * @param  array<string, mixed>  $params
     * @return array{method: string, address_id: string|null, slot_id: string|null, slot_label: string|null, address_label: string|null}|null
     */
    public static function choiceFor(Order $order, array $params): ?array
    {
        $choice = $params['shipping'][$order->account->ulid ?? ''] ?? null;
        if (! is_array($choice) || ! in_array($choice['method'] ?? null, ['pickup', 'dropoff'], true)) {
            return null;
        }

        return [
            'method' => $choice['method'],
            'address_id' => filled($choice['address_id'] ?? null) ? (string) $choice['address_id'] : null,
            'slot_id' => filled($choice['slot_id'] ?? null) ? (string) $choice['slot_id'] : null,
            'slot_label' => filled($choice['slot_label'] ?? null) ? mb_substr((string) $choice['slot_label'], 0, 100) : null,
            'address_label' => filled($choice['address_label'] ?? null) ? mb_substr((string) $choice['address_label'], 0, 255) : null,
        ];
    }

    /**
     * After the marketplace booked the carrier: keeps the choice on the order and remembers it for the shop.
     *
     * @param  array{method: string, address_id: string|null, slot_id: string|null, slot_label: string|null, address_label: string|null}  $choice
     */
    public function arranged(Order $order, array $choice): void
    {
        $order->forceFill(['ship_arrangement' => [
            'method' => $choice['method'],
            'slot' => $choice['method'] === 'pickup' ? $choice['slot_label'] : null,
            'address' => $choice['method'] === 'pickup' ? $choice['address_label'] : null,
            'at' => now()->toIso8601String(),
        ]])->save();
        $account = $order->account;
        $last = ['method' => $choice['method'], 'address_id' => $choice['address_id']];
        if ($account !== null && ($account->settings['ship_choice'] ?? null) !== $last) {
            $account->forceFill(['settings' => [...($account->settings ?? []), 'ship_choice' => $last]])->save();
        }
    }

    /**
     * "Đóng gói xong thì tự chuẩn bị hàng": the shop's last way of handing over, with the earliest pickup slot.
     * Null when nothing fits (the packer then chooses in the dialog).
     *
     * @return array<string, array<string, string|null>>|null params.shipping for OrderActions
     */
    public function autoChoice(Order $order): ?array
    {
        $connector = $this->connector($order);
        if ($connector === null) {
            return null;
        }
        $account = $order->account;
        $options = $connector->shipOptions($account, $order->external_order_id);
        $last = (array) ($account->settings['ship_choice'] ?? []);
        $addresses = collect($options['pickup']['addresses'])->filter(fn ($a) => $a['slots'] !== []);
        $address = $addresses->firstWhere('id', (string) ($last['address_id'] ?? '')) ?? $addresses->first();
        $pickup = $options['pickup']['available'] && $address !== null;
        $dropoff = $options['dropoff']['available'];

        $method = match (true) {
            ($last['method'] ?? null) === 'dropoff' && $dropoff => 'dropoff',
            $pickup => 'pickup',
            $dropoff => 'dropoff',
            default => null,
        };
        if ($method === null) {
            return null;
        }

        return [$account->ulid => $method === 'pickup'
            ? ['method' => 'pickup', 'address_id' => $address['id'], 'slot_id' => $address['slots'][0]['id'], 'slot_label' => $address['slots'][0]['label'], 'address_label' => $address['label']]
            : ['method' => 'dropoff', 'address_id' => null, 'slot_id' => null, 'slot_label' => null, 'address_label' => null]];
    }

    /**
     * The marketplace labels of arranged orders, as PDF (base64) per order, and counts them as printed.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{files: list<array{id: string, external_order_id: string, pdf: string}>, failed: list<array{id: string, external_order_id: string, message: string}>}
     */
    public function labels(Collection $orders): array
    {
        $files = [];
        $failed = [];
        foreach ($orders as $order) {
            $connector = $this->connector($order);
            $message = match (true) {
                $connector === null => 'Đơn này không có phiếu sàn.',
                ! in_array($order->status, self::LABEL_FROM, true) => 'Đơn chưa chuẩn bị hàng nên sàn chưa có phiếu.',
                default => null,
            };
            if ($message === null) {
                try {
                    $files[] = ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'pdf' => base64_encode($connector->shippingLabel($order->account, $order->external_order_id))];

                    continue;
                } catch (ConnectorException $e) {
                    $message = preg_replace('#^/\S+: #', '', $e->getMessage());
                }
            }
            $failed[] = ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'message' => $message];
        }
        $printed = collect($files)->pluck('id');
        if ($printed->isNotEmpty()) {
            Order::query()->whereIn('ulid', $printed)->update(['label_printed_at' => now(), 'label_print_count' => DB::raw('label_print_count + 1')]);
            $this->audit->record('order.label_printed', null, ['orders' => $printed->all()]);
        }

        return ['files' => $files, 'failed' => $failed];
    }
}
