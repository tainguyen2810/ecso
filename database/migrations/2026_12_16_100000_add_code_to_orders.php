<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mã đơn hàng like Sapo (SON50892): orders.code is the shop's own running number, shown first everywhere; the
 * marketplace / Woo number stays in external_order_id. Null until the shop starts its numbering (Cấu hình › Mã đơn
 * hàng): screens then show external_order_id as before. reference = a code typed by hand (Woo, another system).
 * Orders brought over from Sapo already carry Sapo's number: it becomes their code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->after('external_order_id');
            $table->string('reference', 100)->nullable()->after('code');
            $table->unique(['tenant_id', 'code'], 'orders_tenant_code_unique');
            $table->index(['tenant_id', 'reference'], 'orders_tenant_reference_index');
        });

        // One row per Sapo number and tenant (two stores imported into one shop could share a number).
        DB::statement("UPDATE orders o JOIN (SELECT MIN(id) id FROM orders WHERE imported_from = 'sapo' GROUP BY tenant_id, external_order_id) f ON f.id = o.id SET o.code = o.external_order_id WHERE CHAR_LENGTH(o.external_order_id) <= 30");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_tenant_code_unique');
            $table->dropIndex('orders_tenant_reference_index');
            $table->dropColumn(['code', 'reference']);
        });
    }
};
