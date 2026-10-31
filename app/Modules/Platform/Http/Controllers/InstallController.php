<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Installer\Installer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Web setup wizard. These routes run without the `web` middleware group: before installation there is
 * no APP_KEY, so cookies, sessions and CSRF cannot work. Each call is authorised by the one-time token
 * stored on the server instead (see Installer).
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    public function page(): View
    {
        return view('install');
    }

    public function status(): JsonResponse
    {
        if ($this->installer->installed()) {
            return response()->json(['data' => ['installed' => true]]);
        }

        try {
            $this->installer->ensureToken();
            $tokenError = null;
        } catch (RuntimeException $e) {
            $tokenError = $e->getMessage();
        }

        return response()->json(['data' => [
            'installed' => false,
            'token_file' => $this->relative((string) config('ecso.installer.token_path')),
            'token_error' => $tokenError,
        ]]);
    }

    public function requirements(Request $request): JsonResponse
    {
        $this->authorizeInstall($request);

        return response()->json(['data' => [...$this->installer->requirements(), 'base_path' => base_path()]]);
    }

    public function database(Request $request): JsonResponse
    {
        $this->authorizeInstall($request);
        $data = $request->validate($this->databaseRules());

        return response()->json(['data' => $this->installer->testDatabase($data['database'])]);
    }

    public function redis(Request $request): JsonResponse
    {
        $this->authorizeInstall($request);
        $data = $request->validate($this->redisRules(required: true));

        return response()->json(['data' => $this->installer->testRedis($data['redis'])]);
    }

    public function install(Request $request): JsonResponse
    {
        $this->authorizeInstall($request);

        $data = $request->validate([
            ...$this->databaseRules(),
            'redis.enabled' => ['required', 'boolean'],
            ...$this->redisRules(required: $request->boolean('redis.enabled')),
            'app.name' => ['required', 'string', 'max:60'],
            'app.url' => ['required', 'url:http,https', 'max:255'],
            'admin.name' => ['required', 'string', 'max:120'],
            'admin.email' => ['required', 'email:rfc', 'max:190'],
            'admin.password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            'bank.name' => ['nullable', 'string', 'max:120'],
            'bank.account_number' => ['nullable', 'string', 'max:40'],
            'bank.account_name' => ['nullable', 'string', 'max:120'],
            'bank.branch' => ['nullable', 'string', 'max:120'],
        ], attributes: [
            'admin.password' => 'mật khẩu',
            'admin.email' => 'email',
            'app.url' => 'địa chỉ website',
        ]);

        $requirements = $this->installer->requirements();
        if (! $requirements['ok']) {
            return response()->json(['message' => 'Máy chủ chưa đạt yêu cầu tối thiểu.', 'code' => 'REQUIREMENTS_FAILED'], 422);
        }

        try {
            $result = $this->installer->install($data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INSTALL_FAILED'], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Cài đặt thất bại: '.class_basename($e).'. Xem storage/logs để biết chi tiết.', 'code' => 'INSTALL_FAILED'], 500);
        }

        return response()->json(['data' => [
            ...$result,
            'base_path' => base_path(),
            'php_binary' => $this->phpCliBinary(),
        ]]);
    }

    /** Once installed the wizard does not exist; before that every call needs the server-side token. */
    private function authorizeInstall(Request $request): void
    {
        abort_if($this->installer->installed(), 404);
        abort_unless($this->installer->tokenMatches($request->header('X-Install-Token')), 403, 'Mã cài đặt không đúng.');
    }

    /** @return array<string, list<mixed>> */
    private function databaseRules(): array
    {
        return [
            'database.host' => ['required', 'string', 'max:255'],
            'database.port' => ['required', 'integer', 'between:1,65535'],
            'database.database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'database.username' => ['required', 'string', 'max:80'],
            'database.password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function redisRules(bool $required): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'redis.host' => [$presence, 'string', 'max:255'],
            'redis.port' => [$presence, 'integer', 'between:1,65535'],
            'redis.password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Under PHP-FPM, PHP_BINARY is the FPM daemon. aaPanel keeps the CLI next to it
     * (/www/server/php/83/sbin/php-fpm → /www/server/php/83/bin/php); otherwise fall back to `php`.
     */
    private function phpCliBinary(): string
    {
        if (! str_contains(basename(PHP_BINARY), 'fpm')) {
            return PHP_BINARY;
        }

        $cli = dirname(PHP_BINARY, 2).'/bin/php';

        return is_executable($cli) ? $cli : 'php';
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }
}
