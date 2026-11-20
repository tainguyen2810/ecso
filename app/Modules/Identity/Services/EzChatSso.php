<?php

namespace App\Modules\Identity\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\EzChatGift;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Đợt 17 "Đăng nhập bằng EzChat". EzChat signs a one-time ticket for its logged-in member:
 *
 *   ticket = base64url(json) "." hex(hmac_sha256(EZCHAT_SECRET, "sso." + base64url(json)))
 *   json   = {v: 1, ts, nonce, user: {id, email, name, emailVerified}, workspace: {id, name}, role, plan: {slug, name, expiresAt, active}}
 *
 * A ticket lives two minutes and is used once. The EzChat user becomes (or is linked to, by e-mail) a banhang user;
 * the EzChat workspace becomes a banhang business, made on first use by its owner or an admin. Members joining later
 * get a role from their EzChat role; a role changed in banhang is kept. Then the gift plan is refreshed.
 *
 * Everything can be switched off (EZCHAT_SSO=off): the own login and sign-up keep working on their own.
 */
class EzChatSso
{
    public const TTL = 120;

    /** EzChat member role => banhang system role for a member who joins. */
    public const ROLES = ['OWNER' => Role::ADMIN, 'ADMIN' => Role::ADMIN, 'SUPERVISOR' => Role::MANAGER, 'AGENT' => Role::ORDER_STAFF];

    public function __construct(private readonly TenantService $tenants, private readonly EzChatGift $gift, private readonly AuditLogger $audit) {}

    public static function enabled(): bool
    {
        return config('marketplaces.ezchat.sso') !== 'off' && filled(config('marketplaces.ezchat.url')) && filled(config('marketplaces.ezchat.secret'));
    }

    /**
     * Checks the signature, age and single use of a ticket.
     *
     * @return array<string, mixed>
     */
    public function verify(string $ticket): array
    {
        [$body, $sig] = array_pad(explode('.', $ticket, 2), 2, '');
        $expected = hash_hmac('sha256', 'sso.'.$body, (string) config('marketplaces.ezchat.secret'));
        if ($body === '' || ! hash_equals($expected, $sig)) {
            throw new RuntimeException('Liên kết đăng nhập không hợp lệ.');
        }
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! isset($data['user']['id'], $data['user']['email'], $data['workspace']['id'], $data['nonce'])) {
            throw new RuntimeException('Liên kết đăng nhập không hợp lệ.');
        }
        if (abs(time() - (int) ($data['ts'] ?? 0)) > self::TTL) {
            throw new RuntimeException('Liên kết đăng nhập đã hết hạn. Mở lại từ EzChat.');
        }
        if (! Cache::add('ezchat-sso:'.$data['nonce'], 1, self::TTL * 2)) {
            throw new RuntimeException('Liên kết đăng nhập đã được dùng. Mở lại từ EzChat.');
        }

        return $data;
    }

    /**
     * The banhang user and business of a verified ticket (made when missing), with the gift applied.
     *
     * @param  array<string, mixed>  $t
     * @return array{user: User, tenant: Tenant, created: bool, gift: string}
     */
    public function provision(array $t): array
    {
        return DB::transaction(function () use ($t) {
            $user = $this->user($t['user']);
            $role = strtoupper((string) ($t['role'] ?? 'AGENT'));
            $tenant = Tenant::query()->where('ezchat_workspace_id', (string) $t['workspace']['id'])->first();
            $created = false;

            if (! $tenant) {
                if (! in_array($role, ['OWNER', 'ADMIN'], true)) {
                    throw new RuntimeException('Chủ shop hoặc quản trị viên cần mở EzChat Bán hàng trước, sau đó bạn mới vào được.');
                }
                $tenant = $this->tenants->create($user, ['name' => Str::limit((string) ($t['workspace']['name'] ?? 'Cửa hàng'), 120, '')]);
                $tenant->forceFill(['ezchat_workspace_id' => (string) $t['workspace']['id']])->save();
                $created = true;
            }

            return TenantContext::run($tenant, function () use ($tenant, $user, $role, $t, $created) {
                $member = TenantUser::query()->where('user_id', $user->id)->first();
                if (! $member) {
                    $member = TenantUser::query()->create([
                        'user_id' => $user->id,
                        'role_id' => Role::system(self::ROLES[$role] ?? Role::ORDER_STAFF)->id,
                        'status' => TenantUser::STATUS_ACTIVE,
                        'joined_at' => now(),
                    ]);
                    $this->audit->record('member.joined', $member, ['via' => 'ezchat', 'ezchat_role' => $role], $tenant->id, $user->id);
                } elseif ($member->status !== TenantUser::STATUS_ACTIVE) {
                    throw new RuntimeException('Tài khoản của bạn trong cửa hàng này đang bị tạm khóa.');
                }
                $gift = EzChatGift::map() !== [] ? $this->gift->apply((array) ($t['plan'] ?? [])) : 'none';

                return ['user' => $user, 'tenant' => $tenant, 'created' => $created, 'gift' => $gift];
            });
        });
    }

    /** @param  array<string, mixed>  $u */
    private function user(array $u): User
    {
        $email = Str::lower((string) $u['email']);
        $verified = (bool) ($u['emailVerified'] ?? false);
        $user = User::query()->where('ezchat_user_id', (string) $u['id'])->first();
        if (! $user && ($existing = User::query()->where('email', $email)->first())) {
            // Linking to an existing account by e-mail only when EzChat has checked the address.
            if (! $verified || $existing->ezchat_user_id !== null) {
                throw new RuntimeException('Email này đã có tài khoản EzChat Bán hàng. Đăng nhập bằng mật khẩu.');
            }
            $user = $existing;
        }
        if (! $user) {
            $user = User::query()->create(['name' => Str::limit((string) ($u['name'] ?? $email), 120, ''), 'email' => $email, 'password' => Str::random(40)]);
            $this->audit->record('auth.registered', $user, ['via' => 'ezchat'], actorId: $user->id);
        }
        if ($user->isLocked()) {
            throw new RuntimeException('Tài khoản đang bị khóa.');
        }
        $user->forceFill([
            'ezchat_user_id' => (string) $u['id'],
            // EzChat checked the address (OTP or Google), so it counts as verified here too.
            'email_verified_at' => $user->email_verified_at ?? ($verified ? now() : null),
            'last_login_at' => now(),
        ])->save();

        return $user;
    }
}
