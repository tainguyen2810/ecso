<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers on every response. The Content-Security-Policy is sent on production only:
 * the Vite dev server injects scripts from another origin during development.
 */
class SecurityHeaders
{
    /** EzChat's app address as its last inbox ticket gave it (see EzChatInbox::open). */
    public const APP_ORIGIN_KEY = 'ezchat-inbox:app-origin';

    /** scheme://host[:port] of an http(s) address, or null. */
    public static function origin(?string $url): ?string
    {
        $p = $url ? parse_url($url) : false;
        if (! is_array($p) || ! in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    /**
     * Addresses the ERP may frame: EzChat's inbox (Tin nhắn). Nothing else.
     *
     * @return list<string>
     */
    public static function frameOrigins(): array
    {
        $list = [self::origin((string) config('marketplaces.ezchat.url'))];
        try {
            $list[] = self::origin((string) Cache::get(self::APP_ORIGIN_KEY));
        } catch (\Throwable) {
            // No cache store yet (installer): only the configured address.
        }

        return array_values(array_unique(array_filter($list)));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $frames = self::frameOrigins();
        $quoted = implode(' ', array_map(fn ($o) => '"'.$o.'"', $frames));

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // camera=(self): "Quét bằng camera" in Đóng gói and Hoàn/Trả; still off for any embedded frame.
        // microphone: only EzChat's inbox frame in Tin nhắn (voice messages, calls); the ERP itself never records.
        $headers->set('Permissions-Policy', 'camera=(self), microphone=('.$quoted.'), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (app()->isProduction()) {
            if ($request->isSecure()) {
                $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            }
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // 'wasm-unsafe-eval': the barcode reader (zxing .wasm, served from our build) on phones without
                // a native BarcodeDetector. It allows compiling WebAssembly only, not JS eval.
                "script-src 'self' 'wasm-unsafe-eval'",
                // Tailwind/Vue inline style bindings.
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                // Tin nhắn frames EzChat's inbox; any other frame stays blocked.
                trim("frame-src 'self' ".implode(' ', $frames)),
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "object-src 'none'",
            ]));
        }

        return $response;
    }
}
