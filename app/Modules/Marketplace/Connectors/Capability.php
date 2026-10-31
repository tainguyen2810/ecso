<?php

namespace App\Modules\Marketplace\Connectors;

/**
 * Operations a connector may offer, and what we know about each one.
 * SUPPORTED is only used after the operation was verified against official documentation (or for the Fake).
 */
final class Capability
{
    public const SUPPORTED = 'supported';

    public const UNSUPPORTED = 'unsupported';

    /** Not verified yet: no access to official docs or credentials. Treated as not available. */
    public const UNKNOWN = 'unknown';

    public const ORDERS_WEBHOOK = 'orders.webhook';

    public const ORDERS_FETCH = 'orders.fetch';

    public const ORDERS_LIST = 'orders.list';

    public const ORDERS_CONFIRM = 'orders.confirm';

    public const ORDERS_PACK = 'orders.pack';

    public const ORDERS_HANDOVER = 'orders.handover';

    public const ORDERS_CANCEL = 'orders.cancel';

    public const SHIPPING_LABEL = 'shipping.label';

    public const PRODUCTS_LIST = 'products.list';

    public const INVENTORY_UPDATE = 'inventory.update';

    public const INVENTORY_GET = 'inventory.get';

    public const RETURNS_LIST = 'returns.list';

    public const ALL = [
        self::ORDERS_WEBHOOK, self::ORDERS_FETCH, self::ORDERS_LIST, self::ORDERS_CONFIRM, self::ORDERS_PACK,
        self::ORDERS_HANDOVER, self::ORDERS_CANCEL, self::SHIPPING_LABEL, self::PRODUCTS_LIST,
        self::INVENTORY_UPDATE, self::INVENTORY_GET, self::RETURNS_LIST,
    ];

    /** Order actions a user can trigger, by internal action name. */
    public const ORDER_ACTIONS = [
        'confirm' => self::ORDERS_CONFIRM,
        'pack' => self::ORDERS_PACK,
        'handover' => self::ORDERS_HANDOVER,
        'cancel' => self::ORDERS_CANCEL,
    ];

    /**
     * @return array<string, string>
     */
    public static function allUnknown(): array
    {
        return array_fill_keys(self::ALL, self::UNKNOWN);
    }
}
