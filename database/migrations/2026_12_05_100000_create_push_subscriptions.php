<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 8 "Báo đơn mới trên điện thoại": a browser (usually the app added to a phone's home screen) that asked to be
 * told about new orders. Pushes carry no payload; the service worker reads the last message by its endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('device', 120)->default('');
            $table->string('last_title', 150)->nullable();
            $table->string('last_body', 300)->nullable();
            $table->string('last_url', 300)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'endpoint_hash']);
            $table->index('endpoint_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
