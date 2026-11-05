<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders are looked up by tracking number (mã vận đơn): search, the duplicate check when it is typed, and each line
 * of a carrier's COD statement (up to 5,000 per file). Only an index is added; no row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'tracking_number'], 'orders_tenant_tracking_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_tenant_tracking_index');
        });
    }
};
