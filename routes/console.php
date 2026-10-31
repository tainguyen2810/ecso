<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Heartbeat read by /health/ready to prove the scheduler is running.
Schedule::call(fn () => Cache::put('ecso:scheduler:heartbeat', now()->timestamp, 600))
    ->name('scheduler-heartbeat')
    ->everyMinute();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Read-only ledger integrity check; mismatches are logged as critical (INVENTORY-ENGINE.md §8).
Schedule::command('ecso:inventory:verify')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Inventory outbox: retries and debounced pushes to marketplaces (INVENTORY-ENGINE.md §7).
Schedule::command('ecso:marketplace:push-due')->everyMinute()->withoutOverlapping()->onOneServer();

// Order reconcile: recovers lost webhooks by polling recently changed orders (ORDER-LIFECYCLE.md §6).
Schedule::command('ecso:orders:reconcile')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
