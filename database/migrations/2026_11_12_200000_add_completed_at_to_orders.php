<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an order became COMPLETED. Marketplace revenue is recognised at completion (the marketplace closed the order
 * and the return window is over), so reports need that date, not the delivery date. Also adds finance.profit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('delivered_at');
            $table->index(['tenant_id', 'completed_at']);
            $table->index(['tenant_id', 'delivered_at']);
        });

        // Orders already completed: the best known date is when they were delivered (or last changed).
        DB::table('orders')->where('status', 'COMPLETED')->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('COALESCE(delivered_at, updated_at)')]);

        // New permission finance.profit (cost of goods and profit) for the system roles; custom roles that could
        // see finance keep seeing profit.
        (new AccessSeeder)->run();
        $profit = DB::table('permissions')->where('key', 'finance.profit')->value('id');
        $view = DB::table('permissions')->where('key', 'finance.view')->value('id');
        if ($profit && $view) {
            $roles = DB::table('role_permissions')->where('permission_id', $view)->pluck('role_id');
            $has = DB::table('role_permissions')->where('permission_id', $profit)->pluck('role_id');
            DB::table('role_permissions')->insert($roles->diff($has)->map(fn ($id) => ['role_id' => $id, 'permission_id' => $profit])->values()->all());
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'completed_at']);
            $table->dropIndex(['tenant_id', 'delivered_at']);
            $table->dropColumn('completed_at');
        });
    }
};
