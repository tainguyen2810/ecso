<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Heartbeat read by /health/ready to prove the scheduler is running.
Schedule::call(fn () => Cache::put('ecso:scheduler:heartbeat', now()->timestamp, 600))
    ->name('scheduler-heartbeat')
    ->everyMinute();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
