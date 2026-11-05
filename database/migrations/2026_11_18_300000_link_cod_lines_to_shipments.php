<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A COD statement line points at the shipment it paid for (shipping round S4). Only adds a column. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_reconciliation_lines', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cod_reconciliation_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
        });
    }
};
