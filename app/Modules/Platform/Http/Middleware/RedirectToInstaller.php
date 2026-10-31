<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Installer\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like WordPress without wp-config.php: a fresh upload (no APP_KEY, no lock file) sends every page to the
 * setup wizard. Runs as global middleware, before the session and cookie middleware that need APP_KEY.
 */
class RedirectToInstaller
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->installed() || $request->is('install', 'install/*', 'build/*', 'favicon.ico')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Hệ thống chưa được cài đặt.', 'code' => 'NOT_INSTALLED'], 503);
        }

        return redirect('/install');
    }
}
