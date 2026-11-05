<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A booked COD statement can be voided as a whole: its order payments and fee voucher are voided with it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_reconciliations', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('note');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('cod_reconciliations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
