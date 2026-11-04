<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit round E: a SKU's usual supplier (with the supplier's own code) for reorder suggestions, and its package size
 * for shipping fees and marketplace listings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('weight_gram')->constrained()->nullOnDelete();
            $table->string('supplier_sku', 64)->nullable()->after('supplier_id');
            $table->unsignedInteger('length_mm')->nullable()->after('supplier_sku');
            $table->unsignedInteger('width_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('width_mm');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['supplier_sku', 'length_mm', 'width_mm', 'height_mm']);
        });
    }
};
