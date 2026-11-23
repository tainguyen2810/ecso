<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt and payment vouchers brought over from the shop's previous software ("Chuyển dữ liệu" › Sổ quỹ), e.g.
 * 'sapo'. They hold no fund here (the money moved in the old software's funds, so fund balances leave them out) but
 * are listed in Thu chi and count in the profit report and the expense book like the shop's own vouchers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->string('imported_from', 20)->nullable();
            $table->index(['tenant_id', 'imported_from'], 'cash_vouchers_tenant_imported_index');
        });
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropIndex('cash_vouchers_tenant_imported_index');
            $table->dropColumn('imported_from');
        });
    }
};
