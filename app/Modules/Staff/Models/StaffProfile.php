<?php

namespace App\Modules\Staff\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * How one member is paid: by month (base salary for standard_days) or by hour, plus allowance, insured salary
 * (0 = no social insurance), dependents for personal income tax, a monthly sales target and the usual shift hours.
 *
 * @property int $user_id
 * @property string $pay_type
 * @property string $base_salary
 * @property string $hourly_rate
 * @property int $standard_days
 * @property string $allowance
 * @property string $insured_salary
 * @property int $dependents
 * @property string $sales_target
 * @property string $shift_start
 * @property string $shift_end
 */
class StaffProfile extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['standard_days' => 'integer', 'dependents' => 'integer'];
    }
}
