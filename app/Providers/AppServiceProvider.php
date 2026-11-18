<?php

namespace App\Providers;

use App\Shared\Database\MariaDbConnection;
use App\Shared\Database\MySqlConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
        // A relation read without eager loading (an N+1 query) is logged so it can be found and fixed; it never
        // breaks a request. LAZY_LOADING_STRICT=true makes it throw instead (for hunting them down locally).
        Model::preventLazyLoading();
        if (! env('LAZY_LOADING_STRICT', false)) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('N+1 query: '.$model::class.'::'.$relation);
            });
        }
    }
}
