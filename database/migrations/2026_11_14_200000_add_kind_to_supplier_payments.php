<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a supplier pays back (after goods were returned to them, or an overpayment) is a supplier payment of kind
 * "refund": it raises what the business has paid back to zero instead of leaving a negative debt that never closes.
 * Every existing row is a payment, so nothing changes for them; rollback drops the column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->string('kind', 10)->default('payment')->after('purchase_order_id'); // payment | refund
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
