<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Installer\Installer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'ecso:install
        {--admin-name= : Tên super admin}
        {--admin-email= : Email super admin}
        {--admin-password= : Mật khẩu super admin (nên nhập tương tác thay vì truyền qua tham số)}
        {--skip-env-check : Bỏ qua ecso:check-env (chỉ dùng khi phát triển)}';

    protected $description = 'Cài đặt ECSO: kiểm tra môi trường, migrate, seed quyền và gói, tạo super admin';

    public function handle(): int
    {
        if (! $this->option('skip-env-check') && $this->call('ecso:check-env') !== self::SUCCESS) {
            $this->error('Sửa các lỗi môi trường ở trên rồi chạy lại.');

            return self::FAILURE;
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        if (User::query()->where('is_super_admin', true)->exists()) {
            $this->info('Đã có super admin, bỏ qua bước tạo tài khoản.');
            app(Installer::class)->writeLock();

            return self::SUCCESS;
        }

        $input = [
            'name' => $this->option('admin-name') ?? text('Tên super admin', required: true),
            'email' => $this->option('admin-email') ?? text('Email super admin', required: true),
            'password' => $this->option('admin-password') ?? password('Mật khẩu (tối thiểu 12 ký tự)', required: true),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'unique:users,email'],
            'password' => ['required', Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $admin = new User;
        $admin->forceFill([
            'name' => $input['name'],
            'email' => strtolower($input['email']),
            'password' => $input['password'],
            'email_verified_at' => now(),
            'is_super_admin' => true,
        ])->save();

        app(Installer::class)->writeLock();
        $this->info("Đã tạo super admin {$admin->email}. Cài đặt hoàn tất.");

        return self::SUCCESS;
    }
}
