<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Chuẩn bị hàng" on Shopee / TikTok from EzChat Bán hàng: how the parcel goes to the carrier (kept here, the
 * marketplace does not report the chosen slot back) and how often the marketplace's shipping label was printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('ship_arrangement')->nullable()->after('ship_by_at');
            $table->unsignedSmallInteger('label_print_count')->default(0)->after('packing_slip_printed_at');
            $table->timestamp('label_printed_at')->nullable()->after('label_print_count');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ship_arrangement', 'label_print_count', 'label_printed_at']);
        });
    }
};
