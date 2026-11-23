<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders brought over from the shop's previous software ("Chuyển dữ liệu" › Đơn hàng cũ), e.g. 'sapo'. They are
 * history only (orders.historical): shown in the order list, reports and the customer's purchases, never moving stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('imported_from', 20)->nullable()->after('historical');
            $table->index(['tenant_id', 'imported_from', 'external_order_id'], 'orders_tenant_imported_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_tenant_imported_index');
            $table->dropColumn('imported_from');
        });
    }
};
