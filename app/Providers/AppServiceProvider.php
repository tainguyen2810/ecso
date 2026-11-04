<?php

namespace App\Providers;

use App\Shared\Database\MariaDbConnection;
use App\Shared\Database\MySqlConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dates bound in the shop's local time zone are converted to UTC, the zone timestamps are stored in.
        Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new MySqlConnection($pdo, $database, $prefix, $config));
        Connection::resolverFor('mariadb', fn ($pdo, $database, $prefix, $config) => new MariaDbConnection($pdo, $database, $prefix, $config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
