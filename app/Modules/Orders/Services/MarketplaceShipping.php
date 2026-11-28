<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Marketplace\Connectors\ArrangesShipment;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\DeliveryType;
use App\Modules\Tenancy\Support\TenantSettings;
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
        private readonly OrderSync $sync,
        private readonly LabelExtras $extras,
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

    /** Marketplace answers (English) the seller can act on, and what they mean for them. */
    private const KNOWN_ERRORS = [
        // Shopee: the order is not released for shipping yet, most often "Chờ - Đang được kiểm tra bởi Shopee".
        '/ready to be shipped|package is not ready/i' => 'Shopee chưa cho chuẩn bị hàng đơn này: đơn đang chờ Shopee kiểm tra hoặc chưa sẵn sàng giao. Đợi Shopee kiểm tra xong (đơn tự cập nhật), rồi bấm Chuẩn bị hàng lại.',
    ];

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
        // One group per shop and kiểu giao: Shopee offers other ways to hand over for Hỏa tốc (Pickup only) than for
        // Nhanh (Drop off or Pickup). Hỏa tốc first, it has the shortest deadline.
        $byGroup = $orders->sortBy(fn (Order $o) => $o->delivery_type === DeliveryType::EXPRESS ? 0 : 1)
            ->groupBy(fn (Order $o) => ($o->marketplace_account_id ?? 'own').'|'.($o->delivery_type ?? ''));
        foreach ($byGroup as $list) {
            /** @var Order $first */
            $first = $list->first();
            $connector = $this->connector($first);
            foreach ($list as $order) {
                $reason = match (true) {
                    $connector === null => 'Đơn này không chuẩn bị hàng qua sàn được.',
                    $order->status !== OrderStatus::READY_TO_PACK => 'Đơn không ở bước Chờ đóng gói.',
                    $order->underMarketplaceReview() => Order::REVIEW_MESSAGE,
                    $order->arranging() => Order::ARRANGING_MESSAGE,
                    default => null,
                };
                if ($reason !== null) {
                    $skipped[] = ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'code' => $order->code, 'message' => $reason];
                }
            }
            $ready = $list->filter(fn (Order $o) => $connector !== null && $o->status === OrderStatus::READY_TO_PACK && ! $o->underMarketplaceReview() && ! $o->arranging())->values();
            if ($ready->isEmpty()) {
                continue;
            }
            $account = $first->account;
            try {
                $options = $connector->shipOptions($account, $ready->first()->external_order_id);
                $error = null;
            } catch (ConnectorException $e) {
                // Often the order was already prepared in the marketplace's own seller page and ECSO has not heard yet:
                // read the orders again, set those aside, and ask again for the ones still waiting.
                // Shopee may also still be checking an order it reported as ready: it is set aside the same way.
                [$ready, $done] = $this->refresh($account, $ready);
                foreach ($done as $order) {
                    $skipped[] = ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'code' => $order->code,
                        'message' => $order->underMarketplaceReview() ? Order::REVIEW_MESSAGE : 'Đơn đã được chuẩn bị trên sàn rồi, trạng thái đã cập nhật.'];
                }
                if ($ready->isEmpty()) {
                    continue;
                }
                $options = null;
                $error = self::message($e);
                if ($done !== []) {
                    try {
                        $options = $connector->shipOptions($account, $ready->first()->external_order_id);
                        $error = null;
                    } catch (ConnectorException $again) {
                        $error = self::message($again);
                    }
                }
            }
            if ($options !== null) {
                $this->rememberMethods($ready->first(), $options);
            }
            $groups[] = [
                // What params.shipping is keyed by (choiceFor): the shop, plus the kiểu giao when the order has one.
                'key' => self::groupKey($first),
                'delivery' => $first->delivery_type === null ? null : ['type' => $first->delivery_type, 'option' => $first->delivery_option],
                'account' => ['id' => $account->ulid, 'name' => $account->shop_name, 'marketplace' => $account->marketplace],
                'orders' => $ready->map(fn (Order $o) => ['id' => $o->ulid, 'external_order_id' => $o->external_order_id, 'code' => $o->code])->all(),
                'options' => $options,
                'error' => $error,
                'last' => $account->settings['ship_choice'] ?? null,
            ];
        }

        return ['groups' => $groups, 'skipped' => $skipped];
    }

    /**
     * Reads the orders from the marketplace again: those still waiting to be packed, and those that moved on.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{0: Collection<int, Order>, 1: list<Order>}
     */
    private function refresh(MarketplaceAccount $account, Collection $orders): array
    {
        $waiting = collect();
        $done = [];
        foreach ($orders as $order) {
            try {
                $order = $this->sync->syncOne($account, $order->external_order_id, 'user')['order'] ?? $order;
            } catch (ConnectorException) {
            }
            if ($order->status === OrderStatus::READY_TO_PACK && ! $order->underMarketplaceReview()) {
                $waiting->push($order);
            } else {
                $done[] = $order;
            }
        }

        return [$waiting->values(), $done];
    }

    /** The marketplace's answer without the API path EzChat puts in front of it, in Vietnamese where it is known. */
    public static function message(ConnectorException $e): string
    {
        $message = (string) preg_replace('#^/\S+: #', '', $e->getMessage());
        foreach (self::KNOWN_ERRORS as $pattern => $vietnamese) {
            if (preg_match($pattern, $message)) {
                return $vietnamese;
            }
        }

        return $message;
    }

    /**
     * The choice sent with a "pack" action for this order: params.shipping is keyed by shop id and kiểu giao
     * (groupKey), or by shop id alone (packing screen, automatic choice).
     *
     * @param  array<string, mixed>  $params
     * @return array{method: string, address_id: string|null, slot_id: string|null, slot_label: string|null, address_label: string|null}|null
     */
    public static function choiceFor(Order $order, array $params): ?array
    {
        $choice = $params['shipping'][self::groupKey($order)] ?? $params['shipping'][$order->account->ulid ?? ''] ?? null;
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

    public static function groupKey(Order $order): string
    {
        $shop = $order->account->ulid ?? '';

        return $order->delivery_type === null ? $shop : $shop.'|'.$order->delivery_type;
    }

    /** How long a learnt "Pickup / Drop off" answer is trusted before it is asked again. */
    public const METHODS_DAYS = 3;

    /**
     * Keeps on the shop which ways the marketplace offers for this order's kiểu giao (from shipOptions).
     *
     * @param  array<string, mixed>  $options
     */
    public function rememberMethods(Order $order, array $options): void
    {
        $account = $order->account;
        if ($account === null) {
            return;
        }
        $methods = array_values(array_filter([
            ($options['pickup']['available'] ?? false) ? 'pickup' : null,
            ($options['dropoff']['available'] ?? false) ? 'dropoff' : null,
        ]));
        $all = (array) ($account->settings['ship_methods'] ?? []);
        $key = $order->delivery_type ?? '_';
        if (($all[$key]['methods'] ?? null) === $methods && now()->subDay()->lt((string) ($all[$key]['at'] ?? '2000-01-01'))) {
            return;
        }
        $all[$key] = ['methods' => $methods, 'at' => now()->toIso8601String()];
        $account->forceFill(['settings' => [...($account->settings ?? []), 'ship_methods' => $all]])->save();
    }

    /**
     * Pickup / Drop off the marketplace offers for this order's kiểu giao, as learnt for its shop; null when not known
     * yet or too old ($stale tells the caller to ask again).
     *
     * @return list<string>|null
     */
    public static function methodsFor(Order $order, ?bool &$stale = null): ?array
    {
        $known = $order->account?->settings['ship_methods'][$order->delivery_type ?? '_'] ?? null;
        $stale = ! is_array($known) || now()->subDays(self::METHODS_DAYS)->gt((string) ($known['at'] ?? '2000-01-01'));

        return is_array($known) ? array_values((array) ($known['methods'] ?? [])) : null;
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
        ], 'arranged_at' => now()])->save();
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
        $extras = (array) TenantSettings::current()->get('print.label_extras');
        foreach ($orders as $order) {
            $connector = $this->connector($order);
            if ($connector !== null && ! in_array($order->status, self::LABEL_FROM, true) && $order->ship_arrangement !== null) {
                // Just prepared ("Chuẩn bị hàng" then straight to print): ECSO has not read the new status yet.
                try {
                    $order = $this->sync->syncOne($order->account, $order->external_order_id, 'user')['order'] ?? $order;
                } catch (ConnectorException) {
                }
            }
            $message = match (true) {
                $connector === null => 'Đơn này không có phiếu sàn.',
                ! in_array($order->status, self::LABEL_FROM, true) && $order->ship_arrangement !== null => 'Sàn đang xử lý đơn vừa chuẩn bị hàng, chưa có phiếu. Thử in lại sau ít giây.',
                ! in_array($order->status, self::LABEL_FROM, true) => 'Đơn chưa chuẩn bị hàng nên sàn chưa có phiếu.',
                default => null,
            };
            if ($message === null) {
                try {
                    $files[] = ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'code' => $order->code, 'pdf' => base64_encode($connector->shippingLabel($order->account, $order->external_order_id)), 'extras' => $this->extras->for($order, $extras)];

                    continue;
                } catch (ConnectorException $e) {
                    $message = preg_replace('#^/\S+: #', '', $e->getMessage());
                    // Often the parcel left already (prepared in the seller centre, picked up): the marketplace stops
                    // giving its label. The order is read again so its status says so.
                    $before = $order->status;
                    try {
                        $order = $this->sync->syncOne($order->account, $order->external_order_id, 'user')['order'] ?? $order;
                    } catch (ConnectorException) {
                    }
                    if ($order->status !== $before && in_array($order->status, OrderStatus::SHIPPED, true)) {
                        $message = 'Đơn đã giao cho đơn vị vận chuyển, sàn không cho in phiếu nữa. Trạng thái đơn đã được cập nhật.';
                    } elseif (str_contains($message, 'All failed')) {
                        $message = 'Sàn từ chối in phiếu cho đơn này (thường do đơn đã giao cho đơn vị vận chuyển hoặc bị huỷ). Kiểm tra đơn trên Seller Center.';
                    }
                }
            }
            $failed[] = ['id' => $order->ulid, 'external_order_id' => $order->external_order_id, 'code' => $order->code, 'message' => $message];
        }
        $printed = collect($files)->pluck('id');
        if ($printed->isNotEmpty()) {
            Order::query()->whereIn('ulid', $printed)->update(['label_printed_at' => now(), 'label_print_count' => DB::raw('label_print_count + 1')]);
            $this->audit->record('order.label_printed', null, ['orders' => $printed->all()]);
        }

        return ['files' => $files, 'failed' => $failed];
    }
}
