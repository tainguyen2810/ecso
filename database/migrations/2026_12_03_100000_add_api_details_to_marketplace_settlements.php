<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 5 "Tiền thực nhận": settlements read from the marketplace's finance API. final = the marketplace settled the
 * order (false: its estimate, shown "Tạm tính"); lines = the fee lines the order page lists; fetched_at = last read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_settlements', function (Blueprint $table) {
            $table->boolean('final')->default(true)->after('net_amount');
            $table->json('lines')->nullable()->after('final');
            $table->timestamp('fetched_at')->nullable()->after('lines');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_settlements', fn (Blueprint $table) => $table->dropColumn(['final', 'lines', 'fetched_at']));
    }
};
