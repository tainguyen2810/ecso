<?php

namespace App\Modules\Platform\Installer;

use App\Modules\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * State and steps of the web setup wizard (/install), the browser counterpart of `ecso:install`.
 *
 * Security model:
 * - The wizard is open only while the app has no APP_KEY and no lock file. Writing `.env` (with a fresh
 *   APP_KEY) is the last step of a successful install, so a configured app can never be re-installed
 *   from the browser, even if the database is unreachable.
 * - Every call must carry a one-time token that the wizard writes to a file on the server. Only someone
 *   with access to the server's files (aaPanel file manager, SSH) can read it, so a stranger who finds a
 *   freshly uploaded site cannot take it over.
 */
class Installer
{
    /** Extensions the web app itself needs. pcntl and redis are checked separately (CLI / optional). */
    public const WEB_EXTENSIONS = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml', 'zip'];

    public function installed(): bool
    {
        return file_exists($this->lockPath()) || filled(config('app.key'));
    }

    /** Returns the existing token or creates one. The token is never sent to the browser. */
    public function ensureToken(): string
    {
        $path = $this->tokenPath();
        if (is_file($path) && strlen($token = trim((string) file_get_contents($path))) === 32) {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0o755, true);
        }
        if (@file_put_contents($path, $token.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Không ghi được file mã cài đặt. Kiểm tra quyền ghi thư mục storage/.');
        }
        @chmod($path, 0o600);

        return $token;
    }

    public function tokenMatches(?string $given): bool
    {
        $path = $this->tokenPath();
        if (! is_string($given) || $given === '' || ! is_file($path)) {
            return false;
        }

        return hash_equals(trim((string) file_get_contents($path)), trim($given));
    }

    /**
     * @return array{ok: bool, items: list<array{key: string, label: string, ok: bool, required: bool, detail: string}>}
     */
    public function requirements(): array
    {
        $items = [];
        $add = function (string $key, string $label, bool $ok, bool $required = true, string $detail = '') use (&$items) {
            $items[] = compact('key', 'label', 'ok', 'required', 'detail');
        };

        $add('php', 'PHP 8.3 trở lên', version_compare(PHP_VERSION, '8.3.0', '>='), true, 'Đang dùng PHP '.PHP_VERSION);
        foreach (self::WEB_EXTENSIONS as $ext) {
            $add("ext-{$ext}", "Extension {$ext}", extension_loaded($ext), true, 'aaPanel: App Store → PHP 8.3 → Setting → Install extensions');
        }
        $add('ext-redis', 'Extension redis', extension_loaded('redis'), false, 'Chỉ cần nếu dùng Redis (khuyến nghị cho production)');
        $add('storage', 'Thư mục storage/ ghi được', is_writable(storage_path()) && is_writable(storage_path('logs')), true, 'Chủ sở hữu thư mục phải là user chạy PHP (www). Không dùng chmod 777.');
        $add('bootstrap-cache', 'Thư mục bootstrap/cache ghi được', is_writable(base_path('bootstrap/cache')), true, 'Chủ sở hữu thư mục phải là user chạy PHP (www).');
        $add('env', 'Ghi được file .env', $this->envWritable(), true, 'Thư mục gốc của mã nguồn (hoặc file .env có sẵn) phải ghi được bởi user www.');
        $add('build', 'Đã có giao diện build sẵn (public/build)', is_file(public_path('build/manifest.json')), true, 'Dùng gói release đã build, hoặc chạy npm ci && npx vite build.');
        $add('vendor', 'Đã có thư viện PHP (vendor/)', is_file(base_path('vendor/autoload.php')), true, 'Dùng gói release, hoặc chạy composer install --no-dev -o.');
        $add('symlink', 'Hàm symlink (liên kết thư mục ảnh sản phẩm)', function_exists('symlink'), false, 'aaPanel: xóa symlink khỏi disable_functions của PHP, hoặc sau khi cài chạy php artisan storage:link bằng terminal.');
        $add('pcntl', 'pcntl cho PHP CLI (Horizon)', function_exists('pcntl_signal'), false, 'Trang web không kiểm tra được PHP CLI. Sau khi cài, chạy php artisan ecso:check-env để chắc chắn.');

        $ok = collect($items)->every(fn ($item) => $item['ok'] || ! $item['required']);

        return ['ok' => $ok, 'items' => $items];
    }

    /**
     * Connects with the given credentials and checks the server version. Never touches the app's
     * configured connection.
     *
     * @param  array{host: string, port: int|string, database: string, username: string, password?: string|null}  $db
     * @return array{ok: bool, message: string, version?: string, has_tables?: bool, has_admin?: bool}
     */
    public function testDatabase(array $db): array
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['database']),
                $db['username'],
                (string) ($db['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
            $version = (string) $pdo->query('select version()')->fetchColumn();
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Không kết nối được database: '.$this->safeMessage($e)];
        }

        if (stripos($version, 'mariadb') !== false) {
            return ['ok' => false, 'version' => $version, 'message' => "Máy chủ là MariaDB ({$version}). ECSO cần MySQL 8.0 trở lên."];
        }
        if (version_compare($version, '8.0.0', '<')) {
            return ['ok' => false, 'version' => $version, 'message' => "MySQL {$version} quá cũ. ECSO cần MySQL 8.0 trở lên."];
        }

        $tables = (int) $pdo->query('select count(*) from information_schema.tables where table_schema = database()')->fetchColumn();
        $hasAdmin = false;
        if ($tables > 0) {
            try {
                $hasAdmin = (int) $pdo->query('select count(*) from users where is_super_admin = 1')->fetchColumn() > 0;
            } catch (Throwable) {
                $hasAdmin = false;
            }
        }

        if ($hasAdmin) {
            return ['ok' => false, 'version' => $version, 'has_tables' => true, 'has_admin' => true,
                'message' => 'Database này đã có ECSO được cài (đã có tài khoản quản trị). Hãy dùng một database trống.'];
        }

        return ['ok' => true, 'version' => $version, 'has_tables' => $tables > 0, 'has_admin' => false,
            'message' => $tables > 0 ? "Kết nối được MySQL {$version}. Database đã có {$tables} bảng; các bảng của ECSO sẽ được tạo thêm." : "Kết nối được MySQL {$version}."];
    }

    /**
     * @param  array{host: string, port: int|string, password?: string|null}  $redis
     * @return array{ok: bool, message: string}
     */
    public function testRedis(array $redis): array
    {
        if (! extension_loaded('redis')) {
            return ['ok' => false, 'message' => 'PHP chưa có extension redis. Cài extension hoặc chọn chạy không dùng Redis.'];
        }

        try {
            $client = new \Redis;
            $client->connect($redis['host'], (int) $redis['port'], 3.0);
            if (filled($redis['password'] ?? null)) {
                $client->auth((string) $redis['password']);
            }
            $client->ping();
            $client->close();

            return ['ok' => true, 'message' => 'Kết nối được Redis.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Không kết nối được Redis: '.$this->safeMessage($e)];
        }
    }

    /**
     * Runs the whole installation. `.env` and the lock file are written only after the database is
     * migrated and the super admin exists, so a failed run can simply be retried.
     *
     * @param  array<string, mixed>  $input  validated wizard input
     * @return array{env_path: string, queue: string, admin_email: string}
     */
    public function install(array $input): array
    {
        $guard = fopen($this->lockPath().'.installing', 'c');
        if ($guard === false || ! flock($guard, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Một lượt cài đặt khác đang chạy.');
        }

        try {
            $db = $input['database'];
            $check = $this->testDatabase($db);
            if (! $check['ok']) {
                throw new RuntimeException($check['message']);
            }

            $useRedis = (bool) ($input['redis']['enabled'] ?? false);
            if ($useRedis && ! ($redisCheck = $this->testRedis($input['redis']))['ok']) {
                throw new RuntimeException($redisCheck['message']);
            }

            @set_time_limit(300);
            $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));

            // Point this request at the new database; nothing else in the app is touched until .env is written.
            $connection = [
                'host' => $db['host'],
                'port' => (string) $db['port'],
                'database' => $db['database'],
                'username' => $db['username'],
                'password' => (string) ($db['password'] ?? ''),
            ];
            $current = array_intersect_key((array) config('database.connections.mysql'), $connection);
            $changed = config('database.default') !== 'mysql' || array_map('strval', $current) != $connection;

            config(['app.key' => $key, 'cache.default' => 'array', 'queue.default' => 'sync']);
            if ($changed) {
                config(['database.default' => 'mysql', 'database.connections.mysql.url' => null]);
                foreach ($connection as $name => $value) {
                    config(["database.connections.mysql.{$name}" => $value]);
                }
                DB::purge('mysql');
            }
            app()->forgetInstance('encrypter');

            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

            if (User::query()->where('is_super_admin', true)->exists()) {
                throw new RuntimeException('Database này đã có tài khoản quản trị. Hãy dùng một database trống.');
            }

            $admin = new User;
            $admin->forceFill([
                'name' => $input['admin']['name'],
                'email' => strtolower($input['admin']['email']),
                'password' => $input['admin']['password'],
                'email_verified_at' => now(),
                'is_super_admin' => true,
            ])->save();

            try {
                Artisan::call('storage:link');
            } catch (Throwable) {
                // The link is optional for installation; the guide explains how to create it by hand.
            }

            $this->writeEnv($this->envValues($input, $key, $useRedis));
            $this->writeLock();

            return [
                'env_path' => app()->environmentFilePath(),
                'queue' => $useRedis ? 'redis' : 'database',
                'admin_email' => $admin->email,
            ];
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
            @unlink($this->lockPath().'.installing');
        }
    }

    /** Called by `ecso:install` too, so both installers leave the same marker. */
    public function writeLock(): void
    {
        $path = $this->lockPath();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, json_encode(['installed_at' => now()->toIso8601String()]).PHP_EOL, LOCK_EX);
        @unlink($this->tokenPath());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function envValues(array $input, string $key, bool $useRedis): array
    {
        $url = rtrim((string) $input['app']['url'], '/');
        $https = str_starts_with($url, 'https://');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $db = $input['database'];
        $redis = $input['redis'] ?? [];
        $bank = $input['bank'] ?? [];

        return [
            'APP_NAME' => (string) $input['app']['name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'LOG_STACK' => 'daily',
            'LOG_LEVEL' => 'info',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),
            'SESSION_ENCRYPT' => 'true',
            'SESSION_SECURE_COOKIE' => $https ? 'true' : 'false',
            'QUEUE_CONNECTION' => $useRedis ? 'redis' : 'database',
            'CACHE_STORE' => $useRedis ? 'redis' : 'database',
            'REDIS_HOST' => (string) ($redis['host'] ?? '127.0.0.1'),
            'REDIS_PORT' => (string) ($redis['port'] ?? '6379'),
            'REDIS_PASSWORD' => filled($redis['password'] ?? null) ? (string) $redis['password'] : 'null',
            'SANCTUM_STATEFUL_DOMAINS' => $host.($port ? ":{$port}" : ''),
            'HEALTH_TOKEN' => Str::random(40),
            'FAKE_MARKETPLACE_ENABLED' => 'false',
            'FAKE_MARKETPLACE_SECRET' => Str::random(40),
            'BILLING_BANK_NAME' => (string) ($bank['name'] ?? ''),
            'BILLING_BANK_ACCOUNT_NUMBER' => (string) ($bank['account_number'] ?? ''),
            'BILLING_BANK_ACCOUNT_NAME' => (string) ($bank['account_name'] ?? ''),
            'BILLING_BANK_BRANCH' => (string) ($bank['branch'] ?? ''),
            'ECSO_ADMIN_2FA' => 'true',
        ];
    }

    /**
     * Builds `.env` from `.env.example`, replacing the given keys and appending any that are missing.
     *
     * @param  array<string, string>  $values
     */
    public function renderEnv(array $values): string
    {
        $template = is_file(base_path('.env.example')) ? (string) file_get_contents(base_path('.env.example')) : '';
        $lines = preg_split('/\r?\n/', $template) ?: [];
        $seen = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Z0-9_]+)=/', $line, $m) && array_key_exists($m[1], $values)) {
                $lines[$i] = $m[1].'='.self::quote($values[$m[1]]);
                $seen[$m[1]] = true;
            }
        }
        foreach ($values as $name => $value) {
            if (! isset($seen[$name])) {
                $lines[] = $name.'='.self::quote($value);
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@,+\-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /** @param array<string, string> $values */
    private function writeEnv(array $values): void
    {
        $path = app()->environmentFilePath();
        // Writing through the path (not rename) keeps a symlinked shared/.env in place.
        if (@file_put_contents($path, $this->renderEnv($values), LOCK_EX) === false) {
            throw new RuntimeException('Không ghi được file .env. Kiểm tra quyền ghi thư mục mã nguồn.');
        }
        @chmod($path, 0o600);
    }

    private function envWritable(): bool
    {
        $path = app()->environmentFilePath();

        return file_exists($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private function lockPath(): string
    {
        return (string) config('ecso.installer.lock_path');
    }

    private function tokenPath(): string
    {
        return (string) config('ecso.installer.token_path');
    }

    /** Driver messages can be long multi-line dumps; keep one short line for the wizard. */
    private function safeMessage(Throwable $e): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200);
    }
}
