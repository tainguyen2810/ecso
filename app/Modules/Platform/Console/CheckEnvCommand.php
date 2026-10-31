<?php

namespace App\Modules\Platform\Console;

use App\Modules\Marketplace\Services\ShopAuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CheckEnvCommand extends Command
{
    protected $signature = 'ecso:check-env {--production : Apply production-only rules even if APP_ENV is not production}';

    protected $description = 'Kiểm tra môi trường máy chủ trước khi cài đặt hoặc deploy';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $production = $this->option('production') || app()->isProduction();

        $this->check('PHP >= 8.3', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION);

        foreach (config('ecso.required_extensions') as $ext) {
            $this->check("ext-{$ext}", extension_loaded($ext));
        }

        $this->check('pcntl_signal không bị disable (Horizon)', function_exists('pcntl_signal'),
            'aaPanel: xóa pcntl_* khỏi disable_functions của PHP CLI');
        $this->check('proc_open khả dụng', function_exists('proc_open'));

        $this->check('APP_KEY đã đặt', filled(config('app.key')));
        $this->check('storage/ ghi được', is_writable(storage_path()) && is_writable(storage_path('logs')));
        $this->check('bootstrap/cache ghi được', is_writable(base_path('bootstrap/cache')));

        $this->check('Kết nối MySQL', $this->probe(fn () => DB::select('select 1')), config('database.default'));
        $this->check('MySQL >= 8.0', $this->probe(function () {
            $version = DB::selectOne('select version() as v')->v;

            return version_compare($version, '8.0.0', '>=') ?: throw new \RuntimeException($version);
        }));
        $this->check('Kết nối Redis', $this->probe(fn () => Redis::connection()->ping()));

        if ($production) {
            $this->check('APP_DEBUG=false', config('app.debug') === false, 'Không bao giờ bật debug trên production');
            $this->check('APP_ENV=production', app()->isProduction());
            $this->check('APP_URL dùng https', str_starts_with((string) config('app.url'), 'https://'));
            $this->check('QUEUE_CONNECTION=redis', config('queue.default') === 'redis');
            $this->check('.env không cho người khác đọc', $this->envNotWorldReadable(), 'chmod 600 .env');
            $this->check('Sàn giả lập (Fake) tắt', ! config('marketplaces.fake.enabled'), 'FAKE_MARKETPLACE_ENABLED=false');
        }

        // Informational: a marketplace without app credentials simply cannot be connected yet.
        foreach (array_keys((array) config('marketplaces.credentials')) as $marketplace) {
            $this->rows[] = ["App credentials {$marketplace}", ShopAuthorizationService::configured($marketplace) ? 'OK' : 'CHƯA CÓ', 'Không bắt buộc; thiếu thì chưa kết nối được sàn này'];
        }

        $this->table(['Kiểm tra', 'Kết quả', 'Ghi chú'], $this->rows);

        if ($this->failed) {
            $this->error('Môi trường chưa đạt yêu cầu.');

            return self::FAILURE;
        }

        $this->info('Môi trường đạt yêu cầu.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $ok, string $note = ''): void
    {
        $this->rows[] = [$label, $ok ? 'OK' : 'LỖI', $note];
        $this->failed = $this->failed || ! $ok;
    }

    private function probe(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function envNotWorldReadable(): bool
    {
        $path = base_path('.env');

        return ! file_exists($path) || (fileperms($path) & 0o004) === 0;
    }
}
