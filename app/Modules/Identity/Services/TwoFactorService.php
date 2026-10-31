<?php

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TOTP two-factor authentication for super admins. The "passed" state lives in the session, so it ends with the
 * session (logout, expiry) and is regenerated on login. A code (time step) can be used only once.
 */
class TwoFactorService
{
    public const SESSION_KEY = 'two_factor_passed_at';

    public const RECOVERY_CODES = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    public static function required(User $user): bool
    {
        return $user->is_super_admin && (bool) config('ecso.admin_two_factor');
    }

    public static function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    public static function passed(Request $request): bool
    {
        return $request->hasSession() && $request->session()->get(self::SESSION_KEY) !== null;
    }

    /** @return array{secret: string, uri: string} */
    public function begin(User $user): array
    {
        if (self::enabled($user)) {
            throw ValidationException::withMessages(['code' => 'Xác thực hai bước đã được bật.']);
        }
        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, (string) config('app.name', 'ECSO'))];
    }

    /** @return list<string> plain recovery codes, shown once */
    public function confirm(User $user, string $code, Request $request): array
    {
        if (self::enabled($user) || $user->two_factor_secret === null) {
            throw ValidationException::withMessages(['code' => 'Hãy bắt đầu cài đặt lại.']);
        }
        $this->checkCode($user, $code);

        $codes = collect(range(1, self::RECOVERY_CODES))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
        ])->save();
        $this->markPassed($request);
        $this->audit->record('auth.two_factor_enabled', $user, actorId: $user->id);

        return $codes;
    }

    public function verify(User $user, ?string $code, ?string $recoveryCode, Request $request): void
    {
        if (! self::enabled($user)) {
            throw ValidationException::withMessages(['code' => 'Chưa bật xác thực hai bước.']);
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            DB::transaction(function () use ($user, $recoveryCode) {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $hash = hash('sha256', Str::upper(trim($recoveryCode)));
                $remaining = (array) $locked->two_factor_recovery_codes;
                if (! in_array($hash, $remaining, true)) {
                    throw ValidationException::withMessages(['recovery_code' => 'Mã khôi phục không đúng hoặc đã dùng.']);
                }
                $locked->forceFill(['two_factor_recovery_codes' => array_values(array_diff($remaining, [$hash]))])->save();
                $this->audit->record('auth.two_factor_recovery_used', $locked, ['remaining' => count($remaining) - 1], actorId: $locked->id);
            });
        } else {
            $this->checkCode($user, (string) $code);
        }

        $this->markPassed($request);
        $this->audit->record('auth.two_factor_passed', $user, actorId: $user->id);
    }

    private function checkCode(User $user, string $code): void
    {
        $step = Totp::verify((string) $user->two_factor_secret, $code, now()->timestamp);
        // Each time step may be used once per user (stops replay of an observed code).
        if ($step === null || ! Cache::add("2fa-step:{$user->id}:{$step}", 1, Totp::PERIOD * 4)) {
            throw ValidationException::withMessages(['code' => 'Mã xác thực không đúng hoặc đã dùng.']);
        }
    }

    private function markPassed(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->migrate(true);
            $request->session()->put(self::SESSION_KEY, now()->timestamp);
        }
    }
}
