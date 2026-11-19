<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 6 "Hàng hoàn từ sàn": a return or refund request read from Shopee / TikTok keeps the marketplace's own status,
 * the buyer's tracking number of the parcel coming back (scanned when it arrives), the date the seller must answer by
 * and the buyer's photos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->string('marketplace_status', 50)->nullable()->after('external_return_id');
            $table->string('return_tracking_number', 64)->nullable()->after('marketplace_status');
            $table->timestamp('respond_by')->nullable()->after('return_tracking_number');
            $table->json('images')->nullable()->after('respond_by');
            $table->index(['tenant_id', 'return_tracking_number']);
        });
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'return_tracking_number']);
            $table->dropColumn(['marketplace_status', 'return_tracking_number', 'respond_by', 'images']);
        });
    }
};
