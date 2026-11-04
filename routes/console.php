<?php

use App\Http\Middleware\Idempotent;
use App\Modules\Sales\Models\CustomerImport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Heartbeat read by /health/ready to prove the scheduler is running.
Schedule::call(fn () => Cache::put('ecso:scheduler:heartbeat', now()->timestamp, 600))
    ->name('scheduler-heartbeat')
    ->everyMinute();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Read-only ledger integrity check; mismatches are logged as critical (INVENTORY-ENGINE.md §8).
// Low-stock e-mail to buyers (Phase 12).
Schedule::command('ecso:inventory:low-stock-digest')->dailyAt('07:30')->withoutOverlapping()->onOneServer();

Schedule::command('ecso:inventory:verify')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('ecso:orders:verify-money')->dailyAt('02:45')->withoutOverlapping()->onOneServer();

// Inventory outbox: retries and debounced pushes to marketplaces (INVENTORY-ENGINE.md §7).
Schedule::command('ecso:marketplace:push-due')->everyMinute()->withoutOverlapping()->onOneServer();

// Order reconcile: recovers lost webhooks by polling recently changed orders (ORDER-LIFECYCLE.md §6).
Schedule::command('ecso:orders:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// WooCommerce webhooks: Woo disables a webhook after failed deliveries; turn ECSO's back on (order sync round B).
Schedule::command('ecso:woo:check-webhooks')->hourlyAt(17)->withoutOverlapping()->onOneServer();

// Fee check: actual marketplace fees vs fee tables, loss orders (round 3 WooCommerce/pricing).
Schedule::command('ecso:marketplace:check-fees')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

// Customers who reached a group's buying threshold join it (only customers with no group yet).
Schedule::command('ecso:customers:auto-group')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Monitoring alerts (queues, failed jobs, scheduler, circuits, disk) — Phase 8.
Schedule::command('ecso:monitor')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Subscriptions: trial end, renewal invoices, scheduled downgrades, past due -> Free (Phase 6).
Schedule::command('ecso:billing:sweep')->hourly()->withoutOverlapping()->onOneServer();

// Idempotency keys of sales and payments are only needed for retries: kept two days.
Schedule::call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(Idempotent::KEEP_DAYS))->delete())
    ->name('idempotency-keys-prune')->dailyAt('03:10')->onOneServer();

// Customer files checked but never imported are dropped after a day (they hold phones and addresses); a background
// import stuck for two hours (its worker died) is marked failed: its transaction was rolled back.
Schedule::call(fn () => CustomerImport::pruneStale())->name('customer-imports-prune')->hourlyAt(23)->onOneServer();
