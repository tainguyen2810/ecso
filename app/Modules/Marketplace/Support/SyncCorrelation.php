<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * One id that follows a piece of sync work across processes: a webhook, the job that processes it, the order it
 * ingests, the stock push that follows. Kept in Laravel's Context, which travels with queued jobs and is added to
 * log lines, and stored on webhook_events, sync_runs, inventory_sync_outbox and sync_errors.
 */
final class SyncCorrelation
{
    public const KEY = 'sync_correlation_id';

    /** The current id, starting one if there is none. */
    public static function current(): string
    {
        $id = Context::get(self::KEY);
        if (! is_string($id) || $id === '') {
            $id = self::fresh();
        }

        return $id;
    }

    /** Starts a new id (a new webhook, a new poll pass). */
    public static function fresh(): string
    {
        $id = (string) Str::ulid();
        Context::add(self::KEY, $id);

        return $id;
    }

    /** Continues the id stored on a record (a webhook event processed again). */
    public static function continue(?string $id): void
    {
        if ($id !== null && $id !== '') {
            Context::add(self::KEY, $id);
        }
    }
}
