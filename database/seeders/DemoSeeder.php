<?php

namespace Database\Seeders;

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Local/E2E demo data only. Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(TenantService $tenants): void
    {
        if (App::isProduction()) {
            $this->command?->error('DemoSeeder không chạy trên production.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        $owner = $this->user('owner@demo.test', 'Nguyễn Văn Chủ');
        $staff = $this->user('staff@demo.test', 'Trần Thị Kho');
        $this->user('admin@demo.test', 'Super Admin', superAdmin: true);

        if ($owner->memberships()->exists()) {
            return;
        }

        $shop = $tenants->create($owner, ['name' => 'Cửa hàng Mẫu']);
        $tenants->create($owner, ['name' => 'Thời trang Demo']);

        // Demo shop on Starter so the members screen has room for invitations (free plan allows 2 users).
        Subscription::withoutGlobalScopes()->where('tenant_id', $shop->id)
            ->update(['plan_id' => Plan::query()->where('key', 'starter')->value('id')]);

        TenantContext::run($shop, fn () => TenantUser::create([
            'user_id' => $staff->id,
            'role_id' => Role::system(Role::WAREHOUSE_STAFF)->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]));
    }

    private function user(string $email, string $name, bool $superAdmin = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => 'password1',
            'email_verified_at' => now(),
            'is_super_admin' => $superAdmin,
        ])->save();

        return $user;
    }
}
