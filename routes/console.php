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

// Every overlap lock has an expiry (minutes). Without one Laravel keeps the lock 24 hours, so a scheduler killed in the
// middle of a command (a deploy restart, out of memory) would silently stop that command for a day.

// Read-only ledger integrity check; mismatches are logged as critical (INVENTORY-ENGINE.md §8).
// Low-stock e-mail to buyers (Phase 12).
Schedule::command('ecso:inventory:low-stock-digest')->dailyAt('07:30')->withoutOverlapping(120)->onOneServer();

Schedule::command('ecso:inventory:verify')->dailyAt('02:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('ecso:orders:verify-money')->dailyAt('02:45')->withoutOverlapping(120)->onOneServer();

// Inventory outbox: retries and debounced pushes to marketplaces (INVENTORY-ENGINE.md §7).
Schedule::command('ecso:marketplace:push-due')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Order reconcile: recovers lost webhooks by polling recently changed orders (ORDER-LIFECYCLE.md §6).
Schedule::command('ecso:orders:reconcile')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Sync housekeeping: passes and webhook events left "running"/"received" by a dead worker are closed or queued again.
Schedule::command('ecso:sync:recover')->everyTenMinutes()->withoutOverlapping(10)->onOneServer();
// Old webhook events and sync passes are dropped (kept 30 days; open sync errors are never dropped).
Schedule::command('ecso:sync:prune')->dailyAt('03:20')->withoutOverlapping(60)->onOneServer();

// WooCommerce webhooks: Woo disables a webhook after failed deliveries; turn ECSO's back on (order sync round B).
// Carrier parcels that heard nothing for a while are asked again (lost webhooks, webhook not set up yet).
Schedule::command('ecso:carriers:track')->everyThirtyMinutes()->withoutOverlapping(25)->onOneServer();

Schedule::command('ecso:woo:check-webhooks')->hourlyAt(17)->withoutOverlapping(50)->onOneServer();

// Fee check: actual marketplace fees vs fee tables, loss orders (round 3 WooCommerce/pricing).
Schedule::command('ecso:marketplace:check-fees')->dailyAt('06:00')->withoutOverlapping(120)->onOneServer();

// Customers who reached a group's buying threshold join it (only customers with no group yet).
Schedule::command('ecso:customers:auto-group')->dailyAt('03:30')->withoutOverlapping(120)->onOneServer();
// Tích điểm (C5): expire points and move customers between tiers.
Schedule::command('ecso:loyalty:nightly')->dailyAt('03:45')->withoutOverlapping(120)->onOneServer();

// Monitoring alerts (queues, failed jobs, scheduler, circuits, disk) — Phase 8.
Schedule::command('ecso:monitor')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Subscriptions: trial end, renewal invoices, scheduled downgrades, past due -> Free (Phase 6).
Schedule::command('ecso:billing:sweep')->hourly()->withoutOverlapping(50)->onOneServer();

// Idempotency keys of sales and payments are only needed for retries: kept two days.
Schedule::call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(Idempotent::KEEP_DAYS))->delete())
    ->name('idempotency-keys-prune')->dailyAt('03:10')->onOneServer();

// Customer files checked but never imported are dropped after a day (they hold phones and addresses); a background
// import stuck for two hours (its worker died) is marked failed: its transaction was rolled back.
Schedule::call(fn () => CustomerImport::pruneStale())->name('customer-imports-prune')->hourlyAt(23)->onOneServer();

// Zalo ZNS (C6): payment reminders go out in the morning, not at night.
Schedule::command('ecso:zalo:payment-reminders')->dailyAt('09:00')->withoutOverlapping(60)->onOneServer();
