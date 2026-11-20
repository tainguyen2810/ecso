<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Đợt 14 "Cảnh báo đơn lỗ": when the owner was told that an order sells at a loss after fees (once per order). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('loss_alerted_at')->nullable()->after('placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('loss_alerted_at');
        });
    }
};
