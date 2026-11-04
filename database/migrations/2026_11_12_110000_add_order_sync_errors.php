<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WooCommerce order sync, round B: errors per order to look at and retry, and orders deleted on the store. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_errors', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 20); // order
            $table->string('external_id', 64);
            $table->string('category', 20); // api | auth | not_found | data | conflict
            $table->string('message', 500);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable(); // synced | dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'resolved_at', 'last_seen_at'], 'sync_errors_tenant_open_index');
            $table->index(['marketplace_account_id', 'resource', 'external_id'], 'sync_errors_account_resource_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('deleted_on_channel_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('deleted_on_channel_at'));
        Schema::dropIfExists('sync_errors');
    }
};
