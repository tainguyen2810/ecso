<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 7 "Nối với EzChat": a store linked to one EzChat workspace. EzChat reads the products, prices and stock and
 * sends the orders closed in chat, signed with the link's own secret. ezchat_link_orders remembers which EzChat order
 * became which order here, so an order sent twice is made once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ezchat_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('workspace_id', 64);
            $table->string('workspace_name', 150)->default('');
            $table->text('secret');
            $table->foreignId('sales_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_stage', 20)->default('pending'); // pending | confirmed
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ezchat_link_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ezchat_link_orders');
        Schema::dropIfExists('ezchat_links');
    }
};
