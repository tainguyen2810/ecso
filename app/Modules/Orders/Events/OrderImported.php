<?php

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A marketplace order was stored for the first time (dispatched after commit). */
class OrderImported
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly int $orderId) {}
}
