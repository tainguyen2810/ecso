<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combos: a product of type "combo" has one SKU with no stock of its own. Its components are other SKUs; selling
 * one combo holds and ships `quantity` of each component. A component reservation records the combo it belongs to
 * so an order line can be read back in combo units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('type', 20)->default('standard')->after('name'); // standard | combo
        });

        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete(); // the combo SKU
            $table->foreignId('component_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['product_variant_id', 'component_variant_id'], 'product_components_unique');
            $table->index(['tenant_id', 'component_variant_id']);
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->foreignId('bundle_variant_id')->nullable()->after('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('bundle_quantity')->nullable()->after('bundle_variant_id');
            // A combo order line holds one reservation per component. New key first: the old one backs the
            // tenant_id foreign key and cannot be dropped while it is the only index starting with tenant_id.
            $table->unique(['tenant_id', 'reference_type', 'reference_id', 'warehouse_id', 'product_variant_id'], 'stock_reservations_reference_variant_unique');
        });
        Schema::table('stock_reservations', fn (Blueprint $table) => $table->dropUnique('stock_reservations_reference_unique'));
    }

    public function down(): void
    {
        Schema::table('stock_reservations', fn (Blueprint $table) => $table->unique(['tenant_id', 'reference_type', 'reference_id', 'warehouse_id'], 'stock_reservations_reference_unique'));
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropUnique('stock_reservations_reference_variant_unique');
            $table->dropConstrainedForeignId('bundle_variant_id');
            $table->dropColumn('bundle_quantity');
        });
        Schema::dropIfExists('product_components');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('type'));
    }
};
