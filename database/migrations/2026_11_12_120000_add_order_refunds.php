<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WooCommerce order sync, round D: every refund made on the store, partial, repeated or by line. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('external_refund_id', 64);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255)->nullable();
            $table->json('lines')->nullable(); // [{order_item_id, external_item_id, quantity, amount}]
            $table->foreignId('order_return_id')->nullable()->constrained('order_returns')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'external_refund_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('refunded_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('refunded_quantity'));
        Schema::dropIfExists('order_refunds');
    }
};
