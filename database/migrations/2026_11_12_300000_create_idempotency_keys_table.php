<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency keys of write requests that must not run twice (a sale at the counter, a payment): a retry after a
 * timeout or a double click with the same key gets the first response back instead of a second order or payment.
 * Rows are pruned after two days. Rollback drops the table only; no other data depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('route', 150);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status_code')->nullable(); // null = still running
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['tenant_id', 'user_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
