<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zalo ZNS (C6): the shop's Zalo Official Account (one per shop) with its app keys and tokens (encrypted), the
 * templates Zalo approved, which template goes out on which order event, and a log of every message sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zalo_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('app_id', 40);
            $table->text('secrets')->nullable(); // encrypted: secret_key, refresh_token, access_token
            $table->timestamp('access_expires_at')->nullable();
            $table->string('oa_id', 40)->nullable();
            $table->string('oa_name', 150)->nullable();
            $table->json('templates')->nullable(); // [{id, name, status, params: [{name, required, type, max}]}]
            $table->json('rules')->nullable(); // {event: {template_id, enabled, params: {param: field}}}
            $table->string('status', 16)->default('connected'); // connected | error | disconnected
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('zalo_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 24);
            $table->string('template_id', 20);
            $table->string('template_name', 150)->nullable();
            $table->string('phone_masked', 20);
            $table->string('buyer_name', 150)->nullable();
            $table->string('status', 12); // sent | failed
            $table->string('error', 255)->nullable();
            $table->string('msg_id', 64)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
            $table->index(['order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zalo_messages');
        Schema::dropIfExists('zalo_accounts');
    }
};
