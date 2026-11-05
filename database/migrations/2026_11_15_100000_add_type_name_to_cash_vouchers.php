<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The voucher type's name as it was when the voucher was made, so renaming a type later does not change what an
 * old (possibly printed) voucher says. Existing vouchers take the type's current name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->string('type_name', 100)->nullable()->after('cash_voucher_type_id');
        });
        DB::table('cash_vouchers')->whereNull('type_name')->update([
            'type_name' => DB::raw('(SELECT t.name FROM cash_voucher_types t WHERE t.id = cash_vouchers.cash_voucher_type_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropColumn('type_name');
        });
    }
};
