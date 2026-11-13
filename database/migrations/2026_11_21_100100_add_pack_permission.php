<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * orders.pack: pack orders on the scan screen (C1). Warehouse staff get it with the system roles; every role a
 * business made itself that could process orders (orders.update) gets it too, so nobody loses the "Đóng gói" step.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AccessSeeder)->run();

        $ids = DB::table('permissions')->pluck('id', 'key');
        if (! isset($ids['orders.pack'], $ids['orders.update'])) {
            return;
        }
        $roles = DB::table('role_permissions')->where('permission_id', $ids['orders.update'])->pluck('role_id');
        $have = DB::table('role_permissions')->where('permission_id', $ids['orders.pack'])->pluck('role_id')->all();
        $rows = $roles->diff($have)->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids['orders.pack']])->values()->all();
        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'orders.pack')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
