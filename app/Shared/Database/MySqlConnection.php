<?php

namespace App\Shared\Database;

use Illuminate\Database\MySqlConnection as BaseConnection;

class MySqlConnection extends BaseConnection
{
    use LocalizedBindings;
}
