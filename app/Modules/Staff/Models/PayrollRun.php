<?php

namespace App\Modules\Staff\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A month's payroll. Draft: figures computed live, with the manager's adjustments (bonus, deduction, note per
 * member). Approved: lines frozen. Paid: one "Lương nhân viên" payment voucher per member in the cash book.
 *
 * @property string $period
 * @property string $status
 * @property array<string, array<string, mixed>>|null $adjustments
 * @property array<int, array<string, mixed>>|null $lines
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 */
class PayrollRun extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id', 'ulid', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'approved_by', 'paid_by'];

    protected function casts(): array
    {
        return ['adjustments' => 'array', 'lines' => 'array', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }
}
