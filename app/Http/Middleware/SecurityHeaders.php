<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers on every response. The Content-Security-Policy is sent on production only:
 * the Vite dev server injects scripts from another origin during development.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // camera=(self): "Quét bằng camera" in Đóng gói and Hoàn/Trả; still off for any embedded frame.
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
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
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "object-src 'none'",
            ]));
        }

        return $response;
    }
}
