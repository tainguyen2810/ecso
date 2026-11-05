<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sync Center round 1 (docs/SYNC_CENTER_AUDIT.md §14): a standard error code and a correlation id on the work the
 * existing sync machinery already records (webhook inbox, stock outbox, sync passes, sync errors), and the sync.*
 * permissions. No new queue table: the queue is read from these rows.
 */
return new class extends Migration
{
    /** new permission => the permission whose roles get it (nobody loses access). */
    private const GRANT = [
        'sync.view' => 'marketplace.view',
        'sync.operate' => 'marketplace.connect',
        'sync.resolve' => 'marketplace.connect',
        'sync.configure' => 'marketplace.connect',
        'sync.logs.export' => 'marketplace.connect',
    ];

    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->char('correlation_id', 26)->nullable()->after('dedupe_key');
            $table->string('error_code', 32)->nullable()->after('result');
            $table->index(['tenant_id', 'status', 'received_at'], 'webhook_events_tenant_status_received');
            $table->index('correlation_id');
        });
        Schema::table('inventory_sync_outbox', function (Blueprint $table) {
            $table->char('correlation_id', 26)->nullable()->after('listing_variant_id');
            $table->string('error_code', 32)->nullable()->after('last_error');
            $table->timestamp('last_attempt_at')->nullable()->after('next_attempt_at');
            $table->index('correlation_id');
        });
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->char('correlation_id', 26)->nullable()->after('resource');
            $table->string('error_code', 32)->nullable()->after('error');
            $table->index('correlation_id');
        });
        Schema::table('sync_errors', function (Blueprint $table) {
            $table->string('error_code', 32)->nullable()->after('category');
            $table->char('correlation_id', 26)->nullable()->after('error_code');
        });

        (new AccessSeeder)->run();
        $ids = DB::table('permissions')->pluck('id', 'key');
        foreach (self::GRANT as $new => $old) {
            if (! isset($ids[$new], $ids[$old])) {
                continue;
            }
            $roles = DB::table('role_permissions')->where('permission_id', $ids[$old])->pluck('role_id');
            $have = DB::table('role_permissions')->where('permission_id', $ids[$new])->pluck('role_id')->all();
            $rows = $roles->diff($have)->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids[$new]])->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::GRANT))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('sync_errors', fn (Blueprint $table) => $table->dropColumn(['error_code', 'correlation_id']));
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn(['correlation_id', 'error_code']);
        });
        Schema::table('inventory_sync_outbox', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn(['correlation_id', 'error_code', 'last_attempt_at']);
        });
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex('webhook_events_tenant_status_received');
            $table->dropIndex(['correlation_id']);
            $table->dropColumn(['correlation_id', 'error_code']);
        });
    }
};
