<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Webhook bodies were kept in webhook_events.payload but never read; order webhooks carry the buyer's name, phone,
 * e-mail and address. New events no longer store the body; this clears the ones already stored (in batches).
 */
return new class extends Migration
{
    public function up(): void
    {
        do {
            $ids = DB::table('webhook_events')->whereNotNull('payload')->orderBy('id')->limit(1000)->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('webhook_events')->whereIn('id', $ids)->update(['payload' => null]);
            }
        } while ($ids->count() === 1000);
    }

    public function down(): void
    {
        // The cleared bodies cannot be restored.
    }
};
