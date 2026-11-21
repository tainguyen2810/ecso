<?php

namespace App\Modules\Staff\Services;

use App\Modules\Staff\Models\StaffProfile;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** The business's active members with their pay profile, as plain rows the staff pages share. */
class StaffDirectory
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<int, array{user_id: int, id: string, name: string, role: string, profile: StaffProfile|null}>
     */
    public function members(?int $onlyUser = null): array
    {
        $rows = DB::table('tenant_users as tu')->join('users as u', 'u.id', '=', 'tu.user_id')->join('roles as r', 'r.id', '=', 'tu.role_id')
            ->where('tu.tenant_id', $this->context->id())->where('tu.status', 'active')
            ->when($onlyUser, fn ($q) => $q->where('u.id', $onlyUser))
            ->orderBy('u.name')->get(['u.id', 'u.ulid', 'u.name', 'r.name as role']);
        $profiles = StaffProfile::query()->whereIn('user_id', $rows->pluck('id'))->get()->keyBy('user_id');

        return $rows->map(fn ($r) => [
            'user_id' => (int) $r->id,
            'id' => (string) $r->ulid,
            'name' => (string) $r->name,
            'role' => (string) $r->role,
            'profile' => $profiles->get($r->id),
        ])->values()->all();
    }

    /** A member of this business by public id, or 404. */
    public function userId(string $ulid): int
    {
        $id = DB::table('tenant_users as tu')->join('users as u', 'u.id', '=', 'tu.user_id')
            ->where('tu.tenant_id', $this->context->id())->where('u.ulid', $ulid)->value('u.id');
        abort_if($id === null, 404, 'Không tìm thấy nhân viên.');

        return (int) $id;
    }

    /** @return array<string, mixed> */
    public static function profileView(?StaffProfile $p): array
    {
        return [
            'title' => $p?->title,
            'pay_type' => $p->pay_type ?? 'month',
            'base_salary' => (float) ($p->base_salary ?? 0),
            'hourly_rate' => (float) ($p->hourly_rate ?? 0),
            'standard_days' => (int) ($p->standard_days ?? 26),
            'allowance' => (float) ($p->allowance ?? 0),
            'insured_salary' => (float) ($p->insured_salary ?? 0),
            'dependents' => (int) ($p->dependents ?? 0),
            'sales_target' => (float) ($p->sales_target ?? 0),
            'shift_start' => $p->shift_start ?? '08:00',
            'shift_end' => $p->shift_end ?? '17:00',
            'set' => $p !== null,
        ];
    }
}
