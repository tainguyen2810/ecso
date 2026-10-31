<?php

namespace App\Modules\Marketplace\Connectors\Data;

final class StockUpdateResult
{
    public const OK = 'ok';

    /** Temporary failure (rate limit, timeout): retried with backoff. */
    public const RETRY = 'retry';

    /** Permanent failure for this SKU (deleted, locked): not retried, the seller is alerted. */
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $externalSkuId,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}
}
