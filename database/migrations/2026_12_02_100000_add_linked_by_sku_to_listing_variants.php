<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tự liên kết khi SKU trùng": a SKU on the marketplace whose seller SKU equals an internal SKU is linked when the
 * products are pulled, without anyone confirming it. The flag tells those links apart on the Liên kết sản phẩm page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_variants', function (Blueprint $table) {
            $table->boolean('linked_by_sku')->default(false)->after('mapping_status');
        });
    }

    public function down(): void
    {
        Schema::table('listing_variants', function (Blueprint $table) {
            $table->dropColumn('linked_by_sku');
        });
    }
};
