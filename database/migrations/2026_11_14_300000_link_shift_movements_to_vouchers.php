<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash put into or taken out of a POS drawer is either moved between the drawer and the store's safe (no change to
 * the cash fund) or real money in or out (buying ice, paying a shipper), which the cashier books as a voucher of a
 * chosen type. The movement points at that voucher. Existing movements have none and read as moves with the safe;
 * rollback drops the column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shift_movements', function (Blueprint $table) {
            $table->foreignId('cash_voucher_id')->nullable()->after('cash_shift_id')->constrained('cash_vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_shift_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_voucher_id'));
    }
};
