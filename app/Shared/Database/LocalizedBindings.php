<?php

namespace App\Shared\Database;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Timestamps are stored in the application time zone (UTC). A date bound to a query in another zone, such as a
 * report bound in the shop's local time, is converted to that zone first instead of being written as its wall-clock
 * time. Dates already in UTC are unchanged.
 */
trait LocalizedBindings
{
    public function prepareBindings(array $bindings)
    {
        $zone = config('app.timezone');
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = CarbonImmutable::instance($value)->setTimezone($zone);
            }
        }

        return parent::prepareBindings($bindings);
    }
}
