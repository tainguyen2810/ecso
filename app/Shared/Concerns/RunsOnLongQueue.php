<?php

namespace App\Shared\Concerns;

/**
 * For queued jobs that may run for minutes (a whole store's products, one shop's order poll, a customer file).
 * They go to the "sync-long" queue on the redis-long connection, whose retry_after is longer than any of them:
 * on the normal connection (retry_after 90 s) Redis would hand a job still running to a second worker.
 * Other queue drivers (sync in tests, database) keep their own connection.
 */
trait RunsOnLongQueue
{
    public const LONG_QUEUE = 'sync-long';

    protected function onLongQueue(): void
    {
        $this->onQueue(self::LONG_QUEUE);
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
    }
}
