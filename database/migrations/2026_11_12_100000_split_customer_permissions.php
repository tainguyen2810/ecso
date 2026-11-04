<?php

use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Importing, exporting and deleting customers get their own permission keys (they were part of customers.manage).
 * Every role that could manage customers keeps those rights, so nobody loses access on deploy; a business can then
 * take them away from a role in Members → Roles.
 */
return new class extends Migration
{
    private const KEYS = ['customers.import', 'customers.export', 'customers.delete'];

    public function up(): void
    {
        (new AccessSeeder)->run();

        $manage = DB::table('permissions')->where('key', 'customers.manage')->value('id');
        $new = DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id');
        if (! $manage || $new->isEmpty()) {
            return;
        }
        $roles = DB::table('role_permissions')->where('permission_id', $manage)->pluck('role_id');
        foreach ($roles as $roleId) {
            foreach ($new as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
