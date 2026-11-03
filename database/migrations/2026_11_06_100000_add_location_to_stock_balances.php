<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per warehouse and SKU: where it is kept (bin / shelf, "điểm lưu kho") and the most to hold (tồn tối đa, used to
 * flag overstock and cap reorder suggestions). Neither changes stock quantities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->unsignedInteger('max_stock')->nullable()->after('safety_stock');
            $table->string('bin_location', 50)->nullable()->after('max_stock');
        });
    }

    public function down(): void
    {
        Schema::table('stock_balances', fn (Blueprint $table) => $table->dropColumn(['max_stock', 'bin_location']));
    }
};
