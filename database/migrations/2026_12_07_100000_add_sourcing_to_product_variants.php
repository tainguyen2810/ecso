<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hàng đặt": SKUs listed on the website at the supplier's price (the website scrapes it) and bought only once a
 * customer orders. ECSO takes the website price, never pushes their stock to the website, and lists the orders
 * waiting for goods ("Chờ nhập hàng") so they can be bought from the supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('sourcing', 10)->default('stock')->after('price_source');
            $table->unsignedInteger('virtual_stock')->default(0)->after('sourcing');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('awaiting_purchase')->default(false)->after('has_shortage');
            $table->index(['tenant_id', 'awaiting_purchase']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'awaiting_purchase']);
            $table->dropColumn('awaiting_purchase');
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['sourcing', 'virtual_stock']);
        });
    }
};
