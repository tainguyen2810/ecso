<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Notifications\TenantInvitationNotification;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public const TTL_DAYS = 7;

    public function __construct(
        private readonly TenantContext $context,
        private readonly RoleGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function invite(string $email, Role $role, User $inviter): TenantInvitation
    {
        $email = Str::lower(trim($email));

        if (! $this->guard->canAssign($role)) {
            throw new AuthorizationException('Bạn không thể mời với vai trò này.');
        }

        return DB::transaction(function () use ($email, $role, $inviter) {
            // Lock the tenant row so concurrent invites cannot both pass the user limit.
            $tenant = $this->context->tenant()->newQuery()->lockForUpdate()->findOrFail($this->context->id());

            $alreadyMember = TenantUser::query()
                ->whereHas('user', fn ($q) => $q->where('email', $email))
                ->exists();
            if ($alreadyMember) {
                throw ValidationException::withMessages(['email' => 'Người này đã là thành viên.']);
            }

            $pending = TenantInvitation::query()
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());

            if ((clone $pending)->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Đã có lời mời đang chờ cho email này.']);
            }

            $this->context->entitlements()->assertWithin(
                'limit.users',
                TenantUser::query()->count() + $pending->count(),
            );

            $token = Str::random(48);
            $invitation = TenantInvitation::create([
                'email' => $email,
                'role_id' => $role->id,
                'token_hash' => TenantInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);

            Notification::route('mail', $email)->notify(new TenantInvitationNotification($tenant, $inviter, $token));
            $this->audit->record('invitation.created', $invitation, ['email' => $email, 'role' => $role->key]);

            return $invitation;
        });
    }

    public function revoke(TenantInvitation $invitation): void
    {
        $invitation->revoked_at = now();
        $invitation->save();
        $this->audit->record('invitation.revoked', $invitation, ['email' => $invitation->email]);
    }

    /**
     * @tenant-bypass the invitee is not a member yet, so the invitation is found by token hash across tenants.
     */
    public function findByToken(string $token): ?TenantInvitation
    {
        return TenantInvitation::withoutGlobalScope(TenantScope::class)
            ->with('tenant', 'role')
            ->where('token_hash', TenantInvitation::hashToken($token))
            ->first();
    }

    public function accept(string $token, User $user): TenantUser
    {
        $invitation = $this->findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            throw ValidationException::withMessages(['token' => 'Lời mời không hợp lệ hoặc đã hết hạn.']);
        }

        if (Str::lower($user->email) !== $invitation->email) {
            throw new AuthorizationException('Lời mời này dành cho email khác.');
        }

        return TenantContext::run($invitation->tenant, function () use ($invitation, $user) {
            return DB::transaction(function () use ($invitation, $user) {
                $membership = TenantUser::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'role_id' => $invitation->role_id,
                        'status' => TenantUser::STATUS_ACTIVE,
                        'invited_by' => $invitation->invited_by,
                        'joined_at' => now(),
                    ],
                );

                $invitation->accepted_at = now();
                $invitation->save();

                $this->audit->record('invitation.accepted', $invitation, ['email' => $invitation->email], actorId: $user->id);

                return $membership;
            });
        });
    }
}
