<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Services\MoneyVerifier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyOrderMoneyCommand extends Command
{
    protected $signature = 'ecso:orders:verify-money {--tenant= : Only check this tenant id}';

    protected $description = 'Đối soát tiền đơn bán (đã thu/đã hoàn khớp phiếu, đơn hủy còn tiền, phiếu trả hoàn vượt). Chỉ đọc, không sửa dữ liệu.';

    public function handle(MoneyVerifier $verifier): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        $problems = [];
        foreach ($tenants as $tenant) {
            array_push($problems, ...TenantContext::run($tenant, fn () => $verifier->verify((int) $tenant->id)));
        }

        if ($problems === []) {
            $this->info('Tiền đơn bán khớp: không phát hiện sai lệch.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' sai lệch tiền đơn bán:');
        $this->table(['tenant', 'order', 'problem', 'expected', 'actual'], array_map('array_values', $problems));
        Log::warning('Order money mismatch', ['count' => count($problems), 'sample' => array_slice($problems, 0, 20)]);

        return self::FAILURE;
    }
}
