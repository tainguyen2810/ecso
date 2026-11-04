<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock accuracy (audit round A):
 *  - a stocktake line keeps the system quantity seen when it was counted, so approving later applies only the
 *    difference found at count time and keeps the sales and receipts made in between;
 *  - every movement keeps the average cost after it, so stock value and losses of a past date use the cost of
 *    that date instead of today's.
 * (Idempotency-Key uses the shared idempotency_keys table of 2026_11_12_300000.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustment_lines', function (Blueprint $table) {
            $table->integer('system_qty')->nullable()->after('quantity');
        });
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->timestamp('counted_at')->nullable()->after('note');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('avg_cost_after', 18, 4)->nullable()->after('unit_cost');
        });

    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('avg_cost_after'));
        Schema::table('inventory_adjustments', fn (Blueprint $table) => $table->dropColumn('counted_at'));
        Schema::table('inventory_adjustment_lines', fn (Blueprint $table) => $table->dropColumn('system_qty'));
    }
};
