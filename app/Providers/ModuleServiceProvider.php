<?php

namespace App\Providers;

use App\Modules\Platform\Console\CheckEnvCommand;
use App\Modules\Platform\Console\InstallCommand;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Policies\TenantInvitationPolicy;
use App\Modules\Tenancy\Policies\TenantPolicy;
use App\Modules\Tenancy\Policies\TenantUserPolicy;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    /** Modules whose Routes/api.php is mounted under /api/v1. */
    private const API_MODULES = ['Identity', 'Tenancy', 'Billing', 'Audit', 'Admin'];

    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(TenantUser::class, TenantUserPolicy::class);
        Gate::policy(TenantInvitation::class, TenantInvitationPolicy::class);

        foreach (self::API_MODULES as $module) {
            Route::middleware('api')->prefix('api/v1')->group(app_path("Modules/{$module}/Routes/api.php"));
        }

        $this->configureAuthLinks();

        if ($this->app->runningInConsole()) {
            $this->commands([CheckEnvCommand::class, InstallCommand::class]);
        }
    }

    /**
     * Mail links point at SPA pages; the SPA calls the API with the same signed parameters.
     */
    private function configureAuthLinks(): void
    {
        $spa = fn (string $path) => rtrim(config('app.url'), '/').$path;

        VerifyEmail::createUrlUsing(function ($user) use ($spa) {
            $apiUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $user->ulid,
                'hash' => sha1($user->getEmailForVerification()),
            ]);

            return $spa('/verify-email?url='.urlencode($apiUrl));
        });

        ResetPassword::createUrlUsing(fn ($user, string $token) => $spa('/reset-password?token='.$token.'&email='.urlencode($user->email)));
    }
}
