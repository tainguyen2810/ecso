<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Scheduled daily at 01:30 (BACKUP_ENABLED). Can be run by hand: `php artisan ecso:backup`. */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'ecso:backup {--force : Chạy cả khi BACKUP_ENABLED=false}';

    protected $description = 'Sao lưu database ra storage/app/backups (gzip), xóa bản cũ';

    public function handle(DatabaseBackup $backup): int
    {
        if (! config('ecso.backup.enabled') && ! $this->option('force')) {
            $this->line('Sao lưu đang tắt (BACKUP_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $r = $backup->run();
        } catch (RuntimeException $e) {
            Log::critical('[ECSO backup] '.$e->getMessage());
            $this->error('Sao lưu thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Đã sao lưu %d bảng, %d dòng → %s (%s KB, %ss). Xóa %d bản cũ.',
            $r['tables'], $r['rows'], $r['file'], number_format($r['bytes'] / 1024, 1), $r['seconds'], $r['deleted']));

        return self::SUCCESS;
    }
}
