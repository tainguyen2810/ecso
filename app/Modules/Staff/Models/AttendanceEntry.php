<?php

namespace App\Modules\Staff\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One member's day set outside the counter: checked in and out on the phone, or set by a manager
 * (hours fixed, a paid leave, an unpaid day off, a late arrival excused).
 *
 * @property int $user_id
 * @property Carbon $day
 * @property Carbon|null $check_in
 * @property Carbon|null $check_out
 * @property string $status
 * @property string $source
 * @property bool $excuse_late
 */
class AttendanceEntry extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'check_in' => 'datetime', 'check_out' => 'datetime', 'excuse_late' => 'boolean', 'lat' => 'float', 'lng' => 'float'];
    }
}
