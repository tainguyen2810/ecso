<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refund can be booked from a return ("Hoàn tiền" on the return): the payment row points at the return so the
 * return knows how much was already paid back and cannot be refunded twice. Nullable, existing rows unchanged;
 * rollback drops the column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->foreignId('order_return_id')->nullable()->after('order_id')->constrained('order_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_return_id');
        });
    }
};
