<?php

namespace App\Modules\Shipping\Connectors;

use RuntimeException;

/**
 * A carrier API call failed. $message is shown to the shop as is (Vietnamese). $uncertain: the request may have
 * reached the carrier (timeout, 5xx), so a parcel may exist; look it up by our own code before trying again.
 */
class CarrierException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $uncertain = false,
        public readonly bool $authFailed = false,
    ) {
        parent::__construct($message);
    }
}
