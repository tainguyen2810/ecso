<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit round D: a transfer can be received short. The line keeps what arrived; the missing part goes to a pending
 * "thất lạc" adjustment at the receiving warehouse, so the loss is approved and reported like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->unsignedInteger('received_qty')->nullable()->after('quantity'); // null = all of it (older transfers)
        });
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('shortage_adjustment_id')->nullable()->after('note')->constrained('inventory_adjustments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', fn (Blueprint $table) => $table->dropConstrainedForeignId('shortage_adjustment_id'));
        Schema::table('stock_transfer_lines', fn (Blueprint $table) => $table->dropColumn('received_qty'));
    }
};
