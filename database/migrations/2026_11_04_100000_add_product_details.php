<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product details used by counters and wholesale: unit of measure, tags, VAT rate and a wholesale price per SKU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit', 30)->nullable()->after('description');
            $table->json('tags')->nullable()->after('unit');
            // NULL = not subject to VAT (or not tracked); 0 = 0% rate.
            $table->decimal('vat_rate', 5, 2)->nullable()->after('tags');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('wholesale_price', 18, 2)->default(0)->after('ref_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('wholesale_price'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['unit', 'tags', 'vat_rate']));
    }
};
