<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order tags and the date promised to the buyer, and exchanges: a return whose credit pays (part of) a new order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('buyer_note');
            $table->date('deliver_on')->nullable()->after('ship_by_at');
            $table->index(['tenant_id', 'deliver_on']);
        });

        Schema::table('order_returns', function (Blueprint $table) {
            $table->foreignId('exchange_order_id')->nullable()->after('order_id')->constrained('orders')->nullOnDelete();
            $table->decimal('exchange_credit', 15, 2)->default(0)->after('refund_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exchange_order_id');
            $table->dropColumn('exchange_credit');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'deliver_on']);
            $table->dropColumn(['tags', 'deliver_on']);
        });
    }
};
