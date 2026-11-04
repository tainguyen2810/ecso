<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counter sales and the money taken for them point at the cashier's open shift, so the drawer count is exact even
 * when someone else refunds, a payment is voided later, or money is taken without a shift. Nullable: older rows keep
 * being counted by the cashier + time window as before. Rollback drops the two columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->after('warehouse_id')->constrained('cash_shifts')->nullOnDelete();
        });
        Schema::table('order_payments', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->after('order_return_id')->constrained('cash_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_shift_id'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_shift_id'));
    }
};
