<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale tax (VAT) of an order, taken from Cấu hình when the order is created and kept on the order, so changing the
 * rate later does not change old orders. Existing orders get rate 0 and tax 0: their totals stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount');
            $table->boolean('prices_include_tax')->default(true)->after('tax_rate');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('prices_include_tax');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'prices_include_tax', 'tax_amount']);
        });
    }
};
