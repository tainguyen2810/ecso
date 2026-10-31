<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Steps [2]–[6] of ORDER-LIFECYCLE §3. The tenant comes only from marketplace_accounts (marketplace + shop id),
 * never from the payload alone; the order itself is re-read from the marketplace API.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return array_map(fn (int $s) => $s + random_int(0, (int) ceil($s / 5)), config('marketplaces.inventory_push.backoff'));
    }

    public function handle(OrderSync $sync): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if (! $event || in_array($event->status, [WebhookEvent::PROCESSED, WebhookEvent::IGNORED], true)) {
            return;
        }

        $account = MarketplaceAccount::query()->withoutGlobalScopes()
            ->where('marketplace', $event->marketplace)->where('external_shop_id', $event->external_shop_id)->first();

        if (! $account || ! $account->isActive()) {
            $this->finish($event, WebhookEvent::IGNORED, 'Shop chưa kết nối hoặc đã ngắt kết nối');

            return;
        }

        $event->forceFill(['tenant_id' => $account->tenant_id, 'marketplace_account_id' => $account->id, 'attempts' => $event->attempts + 1])->save();

        if ($event->external_order_id === null) {
            $this->finish($event, WebhookEvent::IGNORED, "Loại sự kiện chưa xử lý: {$event->event_type}");

            return;
        }

        $tenant = Tenant::query()->findOrFail($account->tenant_id);

        try {
            $result = TenantContext::run($tenant, function () use ($sync, $event) {
                $account = MarketplaceAccount::query()->findOrFail($event->marketplace_account_id);

                return $sync->syncOne($account, $event->external_order_id, 'webhook', $event->id);
            });
            $this->finish($event, WebhookEvent::PROCESSED, $result['result']);
        } catch (UnsupportedCapability $e) {
            $this->finish($event, WebhookEvent::FAILED, $e->getMessage());
        } catch (ConnectorException $e) {
            if (! $e->temporary || $this->attempts() >= $this->tries) {
                $this->finish($event, WebhookEvent::FAILED, $e->getMessage());

                return;
            }
            $event->forceFill(['result' => mb_substr($e->getMessage(), 0, 500)])->save();
            $this->release($this->backoff()[min($this->attempts() - 1, 4)]);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($event = WebhookEvent::query()->find($this->webhookEventId)) {
            $this->finish($event, WebhookEvent::FAILED, $e->getMessage());
        }
    }

    private function finish(WebhookEvent $event, string $status, string $result): void
    {
        $event->forceFill(['status' => $status, 'result' => mb_substr($result, 0, 500), 'processed_at' => now()])->save();
    }
}
