<?php

use App\Modules\Platform\Http\Controllers\InstallController;
use App\Modules\Platform\Services\DatabaseBackup;
use App\Modules\Platform\Services\SystemMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/** A6: daily database backup written by PHP, and the installer's last step under open_basedir. */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ecso-backup-test-'.uniqid();
    config(['ecso.backup.path' => $this->dir, 'ecso.backup.enabled' => true, 'ecso.backup.keep_days' => 14]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function backupSql(string $dir): string
{
    $files = glob($dir.'/ecso-*.sql.gz');
    expect($files)->toHaveCount(1);

    return (string) gzdecode((string) file_get_contents($files[0]));
}

it('dumps every table with its rows, and the dump restores into an empty database', function () {
    config(['ecso.backup.connection' => config('database.default')]); // same connection, so it sees this test's rows
    [$tenant] = tenantWithOwner("Shop 'Cô Ba' \\ \"Sài Gòn\"");

    $this->artisan('ecso:backup')->assertSuccessful();

    $sql = backupSql($this->dir);
    expect($sql)->toContain('CREATE TABLE `tenants`')->toContain('CREATE TABLE `users`')->toContain('INSERT INTO `tenants`')
        ->and(glob($this->dir.'/ecso-*.sql.gz.sha256'))->toHaveCount(1)
        ->and(app(DatabaseBackup::class)->status())->toMatchArray(['ok' => true])
        ->and(app(DatabaseBackup::class)->problem())->toBeNull();

    // Restore on a separate connection into a scratch database: the tables and the awkward name come back intact.
    $base = config('database.connections.'.config('database.default'));
    config(['database.connections.restore_test' => [...$base, 'database' => 'ecso_restore_test']]);
    $admin = new PDO("mysql:host={$base['host']};port={$base['port']}", $base['username'], $base['password']);
    $admin->exec('DROP DATABASE IF EXISTS ecso_restore_test');
    $admin->exec('CREATE DATABASE ecso_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $restore = DB::connection('restore_test');
        // Values never hold a raw newline (they are escaped), so ";\n" only ends statements.
        foreach (preg_split('/;\n/', $sql) as $statement) {
            $statement = trim((string) preg_replace('/^--.*$/m', '', $statement));
            if ($statement !== '') {
                $restore->getPdo()->exec($statement);
            }
        }
        expect($restore->table('tenants')->where('id', $tenant->id)->value('name'))->toBe("Shop 'Cô Ba' \\ \"Sài Gòn\"")
            ->and(count($restore->select('SHOW TABLES')))->toBe(count(DB::select('SHOW TABLES')));
    } finally {
        DB::purge('restore_test');
        $admin->exec('DROP DATABASE IF EXISTS ecso_restore_test');
    }
});

it('uses a connection of its own by default and leaves the app connection alone', function () {
    $level = DB::transactionLevel();

    $result = app(DatabaseBackup::class)->run();

    expect($result['tables'])->toBeGreaterThan(10)->and(backupSql($this->dir))->toContain('CREATE TABLE `orders`')
        ->and(DB::transactionLevel())->toBe($level)
        ->and(array_keys(DB::getConnections()))->not->toContain('ecso_backup');
});

it('records a failure, exits with an error and lets the monitor alert', function () {
    File::ensureDirectoryExists(dirname($this->dir));
    file_put_contents($this->dir, 'not a folder'); // the backup folder path is taken by a file
    Log::spy();

    $this->artisan('ecso:backup')->assertFailed();
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'Không tạo được thư mục'))->once();

    unlink($this->dir);
    mkdir($this->dir);
    file_put_contents($this->dir.'/last-backup.json', json_encode(['ok' => false, 'at' => now()->toIso8601String(), 'error' => 'Ổ đĩa đầy']));
    $monitor = app(SystemMonitor::class);
    expect($monitor->problems($monitor->snapshot()))->toContain('Sao lưu database lỗi: Ổ đĩa đầy');

    file_put_contents($this->dir.'/last-backup.json', json_encode(['ok' => true, 'at' => now()->subHours(27)->toIso8601String()]));
    expect(app(DatabaseBackup::class)->problem())->toBe('Quá 26 giờ chưa có bản sao lưu database mới');

    config(['ecso.backup.enabled' => false]);
    expect(app(DatabaseBackup::class)->problem())->toBeNull();
    $this->artisan('ecso:backup')->assertSuccessful()->expectsOutputToContain('đang tắt');
});

it('deletes backups older than the keep window but always keeps the newest three', function () {
    mkdir($this->dir);
    $old = now()->subDays(30)->getTimestamp();
    foreach (['20261001T013000Z', '20261002T013000Z', '20261003T013000Z', '20261004T013000Z', '20261005T013000Z'] as $stamp) {
        $file = "{$this->dir}/ecso-db-{$stamp}.sql.gz";
        file_put_contents($file, 'x');
        file_put_contents($file.'.sha256', 'x');
        touch($file, $old);
    }
    $fresh = "{$this->dir}/ecso-db-20261113T013000Z.sql.gz";
    file_put_contents($fresh, 'x');

    expect(app(DatabaseBackup::class)->prune())->toBe(3);
    $left = array_map('basename', glob($this->dir.'/ecso-*.sql.gz'));
    sort($left);
    expect($left)->toBe(['ecso-db-20261004T013000Z.sql.gz', 'ecso-db-20261005T013000Z.sql.gz', 'ecso-db-20261113T013000Z.sql.gz'])
        ->and(file_exists("{$this->dir}/ecso-db-20261001T013000Z.sql.gz.sha256"))->toBeFalse();
});

it('shows the PHP CLI path on the last install step without touching files outside open_basedir', function () {
    $fpm = '/www/server/php/83/sbin/php-fpm';

    expect(InstallController::phpCliBinary($fpm, '/www/wwwroot/erp.example.vn/:/tmp/'))->toBe('/www/server/php/83/bin/php')
        ->and(InstallController::phpCliBinary('/usr/bin/php8.3', '/www/wwwroot/x/'))->toBe('/usr/bin/php8.3')
        ->and(InstallController::phpCliBinary('/nonexistent/sbin/php-fpm8.3', ''))->toBe('php')
        ->and(InstallController::insideOpenBasedir('/www/wwwroot/a/artisan', '/www/wwwroot/a/:/tmp/'))->toBeTrue()
        ->and(InstallController::insideOpenBasedir('/www/wwwroot/ab/artisan', '/www/wwwroot/a'))->toBeFalse()
        ->and(InstallController::insideOpenBasedir('/anything', ''))->toBeTrue();
});
