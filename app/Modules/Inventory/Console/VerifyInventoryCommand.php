<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Services\StockVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyInventoryCommand extends Command
{
    protected $signature = 'ecso:inventory:verify {--tenant= : Only check this tenant id}';

    protected $description = 'Kiểm tra bất biến sổ kho (balance = Σ movement = Σ reservation). Chỉ đọc, không sửa dữ liệu.';

    public function handle(StockVerifier $verifier): int
    {
        $tenant = $this->option('tenant');
        $problems = $verifier->verify($tenant !== null ? (int) $tenant : null);

        if ($problems === []) {
            $this->info('Sổ kho khớp: không phát hiện sai lệch.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' sai lệch sổ kho:');
        $this->table(['tenant', 'warehouse', 'variant', 'problem', 'expected', 'actual'], array_map('array_values', $problems));
        Log::critical('Inventory ledger mismatch', ['count' => count($problems), 'sample' => array_slice($problems, 0, 20)]);

        return self::FAILURE;
    }
}
