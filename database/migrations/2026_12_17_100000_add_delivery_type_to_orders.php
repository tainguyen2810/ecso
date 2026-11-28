<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểu giao of marketplace orders: delivery_option = the marketplace's words for what the buyer chose ("Hỏa Tốc",
 * "Nhanh"), delivery_type = its group (express / standard / economy / bulky / other) for the filter on the order list.
 * Orders already in ECSO get a group from their carrier now (SPX Instant = Hỏa tốc); the words come with the next sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_option', 100)->nullable()->after('shipping_carrier');
            $table->string('delivery_type', 16)->nullable()->after('delivery_option');
            $table->index(['tenant_id', 'delivery_type'], 'orders_tenant_delivery_type_index');
        });

        DB::table('orders')->whereNotNull('shipping_carrier')->where(fn ($q) => $q->where('shipping_carrier', 'like', '%instant%')->orWhere('shipping_carrier', 'like', '%hỏa tốc%')->orWhere('shipping_carrier', 'like', '%hoả tốc%'))
            ->update(['delivery_type' => 'express']);
        DB::table('orders')->whereNull('delivery_type')->where(fn ($q) => $q->where('shipping_carrier', 'like', '%bulky%')->orWhere('shipping_carrier', 'like', '%cồng kềnh%'))
            ->update(['delivery_type' => 'bulky']);
        DB::table('orders')->whereNull('delivery_type')->where(fn ($q) => $q->where('shipping_carrier', 'like', 'spx express%')->orWhere('shipping_carrier', 'like', 'shopee express%'))
            ->update(['delivery_type' => 'standard']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_tenant_delivery_type_index');
            $table->dropColumn(['delivery_option', 'delivery_type']);
        });
    }
};
