<?php

namespace App\Modules\Platform\Console;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Database\Seeders\Demo\DemoHistory;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Creates one demo business with sample data (catalog, stock, customers, suppliers, purchases and daily own-channel
 * sales for the last N days) under a new login, for showing the product on a demo server. Never creates a super
 * admin and never turns on the marketplace simulator: marketplace orders only appear when FAKE_MARKETPLACE_ENABLED is
 * already true (local/E2E). On production it needs --force, and the business name says it is sample data.
 */
class DemoTenantCommand extends Command
{
    protected $signature = 'ecso:demo-tenant {email : Email đăng nhập của tài khoản demo (tạo mới)}
        {--name=Cửa hàng Demo : Tên doanh nghiệp demo}
        {--days=60 : Số ngày lịch sử bán hàng (7–180)}
        {--force : Cho phép chạy khi APP_ENV=production}';

    protected $description = 'Tạo tài khoản demo với một doanh nghiệp đầy đủ dữ liệu mẫu';

    public function handle(TenantService $tenants): int
    {
        if (App::isProduction() && ! $this->option('force')) {
            $this->error('Đang ở production: thêm --force nếu đây là máy chủ demo.');

            return self::FAILURE;
        }
        $email = Str::lower(trim((string) $this->argument('email')));
        $days = (int) $this->option('days');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $days < 7 || $days > 180) {
            $this->error('Email không hợp lệ hoặc số ngày ngoài khoảng 7–180.');

            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->error("Email {$email} đã có tài khoản. Dùng một email mới cho tài khoản demo.");

            return self::FAILURE;
        }

        [$local, $domain] = explode('@', $email, 2);
        $password = Str::password(14, symbols: false);
        $owner = $this->user($email, 'Chủ cửa hàng Demo', $password);
        $staff = $this->user("{$local}+kho@{$domain}", 'Nhân viên kho Demo', $password);
        $name = trim((string) $this->option('name')).' (dữ liệu mẫu)';

        $this->info("Đang tạo \"{$name}\" với {$days} ngày lịch sử…");
        $realNow = Carbon::now();
        // Catalog, opening stock and the fixed sample documents are dated at the start of the history.
        Carbon::setTestNow($realNow->copy()->subDays($days + 1)->setTime(9, 0));
        try {
            Auth::setUser($owner);
            $shop = $tenants->create($owner, ['name' => $name]);
            app(DemoSeeder::class)->populate($shop, $owner, $staff);
        } finally {
            Carbon::setTestNow();
        }

        Auth::setUser($owner);
        $orders = TenantContext::run($shop, fn () => app(DemoHistory::class)->run($days));

        // The sample subscription period is relative to today, not to the start of the history.
        Subscription::withoutGlobalScopes()->where('tenant_id', $shop->id)->update([
            'current_period_start' => $realNow->copy()->subDays(10),
            'current_period_end' => $realNow->copy()->subDays(10)->addMonth(),
        ]);

        $this->table(['', ''], [
            ['Doanh nghiệp', $name],
            ['Đăng nhập (chủ)', $email],
            ['Đăng nhập (nhân viên kho)', "{$local}+kho@{$domain}"],
            ['Mật khẩu (cả hai)', $password],
            ['Đơn bán trong lịch sử', (string) $orders],
        ]);
        $this->warn('Mật khẩu chỉ hiện một lần. Đổi mật khẩu sau khi đăng nhập nếu cần.');

        return self::SUCCESS;
    }

    private function user(string $email, string $name, string $password): User
    {
        $user = new User;
        $user->forceFill(['email' => $email, 'name' => $name, 'password' => $password, 'email_verified_at' => now(), 'is_super_admin' => false])->save();

        return $user;
    }
}
