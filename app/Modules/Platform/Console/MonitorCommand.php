<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Services\SystemMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every 5 minutes. Logs a CRITICAL entry when something needs attention; with LOG_STACK including
 * `slack` (LOG_SLACK_WEBHOOK_URL) the alert reaches the operators. The same problem alerts at most once per hour.
 */
class MonitorCommand extends Command
{
    protected $signature = 'ecso:monitor';

    protected $description = 'Kiểm tra queue, job lỗi, scheduler, ngắt mạch, ổ đĩa và cảnh báo';

    public function handle(SystemMonitor $monitor): int
    {
        $problems = $monitor->problems($monitor->snapshot());
        foreach ($problems as $problem) {
            if (Cache::add('ecso:monitor:'.sha1($problem), 1, 3600)) {
                Log::critical('[ECSO monitor] '.$problem);
            }
            $this->warn($problem);
        }
        if ($problems === []) {
            $this->info('OK');
        }

        return self::SUCCESS;
    }
}
