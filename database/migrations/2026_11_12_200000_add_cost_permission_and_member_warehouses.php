<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit round C: "products.cost.view" (see cost prices, stock value, receipt unit costs) and warehouses per member.
 * Custom roles that already see money (reports, product or purchasing management) keep seeing costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AccessSeeder)->run();

        $cost = DB::table('permissions')->where('key', 'products.cost.view')->value('id');
        $money = DB::table('permissions')->whereIn('key', ['reports.view', 'products.manage', 'purchasing.manage'])->pluck('id');
        $roles = DB::table('roles')->whereNotNull('tenant_id')
            ->whereIn('id', DB::table('role_permissions')->whereIn('permission_id', $money)->select('role_id'))
            ->whereNotIn('id', DB::table('role_permissions')->where('permission_id', $cost)->select('role_id'))
            ->pluck('id');
        DB::table('role_permissions')->insert($roles->map(fn ($id) => ['role_id' => $id, 'permission_id' => $cost])->all());

        // A member with rows here works only in those warehouses; no rows = every warehouse (as before).
        Schema::create('member_warehouses', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->primary(['tenant_user_id', 'warehouse_id']);
            $table->index(['tenant_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_warehouses');
    }
};
