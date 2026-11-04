<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đơn nháp: an order being prepared (a quote, a chat with a buyer who has not decided). It is only the form's
 * content: no order number, no stock held, no debt, nothing in reports until it is turned into a real order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_drafts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 191);
            $table->decimal('total', 15, 2)->default(0);
            $table->unsignedInteger('lines_count')->default(0);
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_drafts');
    }
};
