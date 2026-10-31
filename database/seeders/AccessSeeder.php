<?php

namespace Database\Seeders;

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Access\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Idempotent: safe to run on every deploy to sync permissions and system roles.
 */
class AccessSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionRegistry::PERMISSIONS as $module => $permissions) {
            foreach ($permissions as $key => $description) {
                Permission::query()->updateOrCreate(['key' => $key], ['module' => $module, 'description' => $description]);
            }
        }

        $ids = Permission::query()->pluck('id', 'key');

        foreach (PermissionRegistry::systemRoles() as $key => $definition) {
            $role = Role::query()->whereNull('tenant_id')->where('key', $key)->first() ?? new Role(['key' => $key]);
            $role->fill(['name' => $definition['name'], 'is_system' => true, 'rank' => $definition['rank']])->save();
            $role->permissions()->sync($ids->only($definition['permissions'])->values());
        }
    }
}
