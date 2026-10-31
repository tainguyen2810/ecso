<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Auth\Access\AuthorizationException;

class MembershipService
{
    public function __construct(private readonly RoleGuard $guard, private readonly AuditLogger $audit) {}

    public function changeRole(TenantUser $member, Role $role): TenantUser
    {
        if (! $this->guard->canManage($member) || ! $this->guard->canAssign($role)) {
            throw new AuthorizationException('Bạn không thể gán vai trò này.');
        }

        $from = $member->role->key;
        $member->role()->associate($role)->save();
        $this->audit->record('member.role_changed', $member, ['from' => $from, 'to' => $role->key]);

        return $member->load('role');
    }

    public function changeStatus(TenantUser $member, string $status): TenantUser
    {
        if (! $this->guard->canManage($member)) {
            throw new AuthorizationException('Bạn không thể thay đổi nhân viên này.');
        }

        $from = $member->status;
        $member->status = $status;
        $member->save();
        $this->audit->record('member.status_changed', $member, ['from' => $from, 'to' => $status]);

        return $member;
    }

    public function remove(TenantUser $member): void
    {
        if (! $this->guard->canManage($member)) {
            throw new AuthorizationException('Bạn không thể xóa nhân viên này.');
        }

        $this->audit->record('member.removed', $member, ['user' => $member->user->email, 'role' => $member->role->key]);
        $member->delete();
    }
}
