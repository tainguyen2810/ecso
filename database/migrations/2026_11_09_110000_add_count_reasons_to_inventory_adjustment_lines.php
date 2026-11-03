<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stocktake like Sapo's "Kiểm hàng": a count is saved as a draft (status "draft", no new column) while staff count,
 * and each counted line can say why it differs (damaged, lost…) with a note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustment_lines', function (Blueprint $table) {
            $table->string('reason', 30)->nullable()->after('quantity');
            $table->string('note', 255)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_lines', function (Blueprint $table) {
            $table->dropColumn(['reason', 'note']);
        });
    }
};
