<?php

namespace App\Providers;

use App\Modules\Billing\Console\BillingSweepCommand;
use App\Modules\Billing\Listeners\CountOrderUsage;
use App\Modules\Billing\Payments\GatewayRegistry;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Policies\ProductPolicy;
use App\Modules\Inventory\Console\VerifyInventoryCommand;
use App\Modules\Inventory\Events\AvailableStockChanged;
use App\Modules\Inventory\Listeners\CreateDefaultWarehouse;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Policies\InventoryPolicy;
use App\Modules\Inventory\Policies\WarehousePolicy;
use App\Modules\Marketplace\Console\PushDueInventoryCommand;
use App\Modules\Marketplace\Events\ListingMappingChanged;
use App\Modules\Marketplace\Events\WebhookReceived;
use App\Modules\Marketplace\Listeners\PlanInventoryPush;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Policies\MarketplacePolicy;
use App\Modules\Orders\Console\ReconcileOrdersCommand;
use App\Modules\Orders\Events\OrderImported;
use App\Modules\Orders\Listeners\FillOrderShortages;
use App\Modules\Orders\Listeners\QueueWebhookProcessing;
use App\Modules\Orders\Listeners\RemapOrderItems;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Orders\Policies\ReturnPolicy;
use App\Modules\Platform\Console\CheckEnvCommand;
use App\Modules\Platform\Console\InstallCommand;
use App\Modules\Tenancy\Events\TenantCreated;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Policies\TenantInvitationPolicy;
use App\Modules\Tenancy\Policies\TenantPolicy;
use App\Modules\Tenancy\Policies\TenantUserPolicy;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    /** Modules whose Routes/api.php is mounted under /api/v1. */
    private const API_MODULES = ['Identity', 'Tenancy', 'Billing', 'Audit', 'Admin', 'Catalog', 'Inventory', 'Marketplace', 'Orders', 'Analytics'];

    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(GatewayRegistry::class);
    }

    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(TenantUser::class, TenantUserPolicy::class);
        Gate::policy(TenantInvitation::class, TenantInvitationPolicy::class);
        foreach ([Product::class, ProductVariant::class, Category::class, Brand::class] as $model) {
            Gate::policy($model, ProductPolicy::class);
        }
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        foreach ([StockBalance::class, StockReceipt::class, InventoryAdjustment::class, StockTransfer::class] as $model) {
            Gate::policy($model, InventoryPolicy::class);
        }

        Gate::policy(MarketplaceAccount::class, MarketplacePolicy::class);
        Gate::policy(ListingVariant::class, MarketplacePolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(OrderReturn::class, ReturnPolicy::class);

        Event::listen(TenantCreated::class, CreateDefaultWarehouse::class);
        Event::listen(OrderImported::class, CountOrderUsage::class);
        Event::listen(WebhookReceived::class, QueueWebhookProcessing::class);
        // Order shortages are filled first, then the marketplaces get the resulting published stock.
        Event::listen(AvailableStockChanged::class, FillOrderShortages::class);
        Event::listen(AvailableStockChanged::class, PlanInventoryPush::class);
        Event::listen(ListingMappingChanged::class, RemapOrderItems::class);
        Event::listen(ListingMappingChanged::class, PlanInventoryPush::class);

        foreach (self::API_MODULES as $module) {
            Route::middleware('api')->prefix('api/v1')->group(app_path("Modules/{$module}/Routes/api.php"));
        }

        $this->configureAuthLinks();

        if ($this->app->runningInConsole()) {
            $this->commands([CheckEnvCommand::class, InstallCommand::class, VerifyInventoryCommand::class, PushDueInventoryCommand::class, ReconcileOrdersCommand::class, BillingSweepCommand::class]);
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
