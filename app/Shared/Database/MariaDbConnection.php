<?php

namespace App\Shared\Database;

use Illuminate\Database\MariaDbConnection as BaseConnection;

class MariaDbConnection extends BaseConnection
{
    use LocalizedBindings;
}
