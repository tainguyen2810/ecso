<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A warehouse can hold defective goods: damaged items found when a return is inspected go there instead of being
 * lost from the books. Such a warehouse never sells. Every existing warehouse stays a selling one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('purpose', 20)->default('sale')->after('status');
        });

        Schema::table('order_returns', function (Blueprint $table) {
            $table->foreignId('damaged_warehouse_id')->nullable()->after('warehouse_id')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('damaged_warehouse_id');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
