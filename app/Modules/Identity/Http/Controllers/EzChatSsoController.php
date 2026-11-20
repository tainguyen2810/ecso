<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\EzChatSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/** Đợt 17 "Đăng nhập bằng EzChat": the button on the login page, and the return from EzChat with a signed ticket. */
class EzChatSsoController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** "Đăng nhập bằng EzChat": EzChat (logging the member in first if needed) signs a ticket and sends them back. */
    public function start(Request $request): RedirectResponse
    {
        abort_unless(EzChatSso::enabled(), 404);
        $back = self::safePath((string) $request->query('redirect', '/'));

        return redirect()->away(rtrim((string) config('marketplaces.ezchat.url'), '/').'/banhang/open?'.http_build_query(['return' => $back]));
    }

    public function callback(Request $request, EzChatSso $sso): RedirectResponse
    {
        abort_unless(EzChatSso::enabled(), 404);
        try {
            $result = $sso->provision($sso->verify((string) $request->query('ticket', '')));
        } catch (RuntimeException $e) {
            return redirect('/login?'.http_build_query(['sso_error' => $e->getMessage()]));
        }

        if (Auth::guard('web')->check() && Auth::guard('web')->id() !== $result['user']->id) {
            Auth::guard('web')->logout();
        }
        Auth::guard('web')->login($result['user'], remember: true);
        $request->session()->regenerate();
        $this->audit->record('auth.login', $result['user'], ['via' => 'ezchat', 'gift' => $result['gift']], $result['tenant']->id, $result['user']->id);

        $back = self::safePath((string) $request->query('return', '/'));

        return redirect($back.(str_contains($back, '?') ? '&' : '?').http_build_query(['tenant' => $result['tenant']->ulid] + ($result['created'] ? ['welcome' => 'ezchat'] : [])));
    }

    /** Only a path of this app (no other host, no protocol-relative URL). */
    private static function safePath(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? mb_substr($path, 0, 300) : '/';
    }
}
