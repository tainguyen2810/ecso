<?php

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Services\EInvoiceService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Polls a pending e-invoice until the provider says issued or failed (providers issue asynchronously). */
class CheckEInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $invoiceId) {}

    public function handle(EInvoiceService $service): void
    {
        $invoice = EInvoice::query()->withoutGlobalScopes()->find($this->invoiceId);
        $tenant = $invoice ? Tenant::query()->find($invoice->tenant_id) : null;
        if (! $invoice || ! $tenant || $invoice->status !== EInvoice::PENDING) {
            return;
        }
        $invoice = TenantContext::run($tenant, fn () => $service->refresh(EInvoice::query()->findOrFail($invoice->id)));
        if ($invoice->status === EInvoice::PENDING && $invoice->checks < (int) config('einvoice.poll_tries')) {
            self::dispatch($invoice->id)->delay(now()->addSeconds((int) config('einvoice.poll_seconds')));
        }
    }
}
