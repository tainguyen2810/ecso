<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 *
 * Creates the bare tenant row only. Tests that need an owner membership and a subscription
 * should use TenantService::create() (see tests/Pest.php helpers).
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'owner_user_id' => User::factory(),
            'status' => Tenant::STATUS_ACTIVE,
        ];
    }
}
