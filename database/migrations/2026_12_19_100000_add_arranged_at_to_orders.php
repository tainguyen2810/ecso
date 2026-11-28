<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When "Chuẩn bị hàng" went through on Shopee / TikTok. The marketplace changes the order's status a few seconds later
 * (Shopee books the carrier in the background), so for a while ECSO still reads READY_TO_PACK: arranged_at keeps such
 * an order out of "Cần chuẩn bị hàng" and the banner until its new status arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('arranged_at')->nullable()->after('ship_arrangement');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('arranged_at');
        });
    }
};
