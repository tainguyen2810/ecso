<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeing the orders of every staff member becomes its own permission (orders.view.all); without it a member sees
 * only the orders they created or were assigned. Nobody loses access: every role that could see orders keeps
 * seeing all of them, including roles a business made itself.
 */
return new class extends Migration
{
    private const SPLIT = ['orders.view.all' => 'orders.view'];

    public function up(): void
    {
        (new AccessSeeder)->run();

        $ids = DB::table('permissions')->pluck('id', 'key');
        foreach (self::SPLIT as $new => $old) {
            if (! isset($ids[$new], $ids[$old])) {
                continue;
            }
            $roles = DB::table('role_permissions')->where('permission_id', $ids[$old])->pluck('role_id');
            $have = DB::table('role_permissions')->where('permission_id', $ids[$new])->pluck('role_id')->all();
            $rows = $roles->diff($have)->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids[$new]])->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::SPLIT))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
