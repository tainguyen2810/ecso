<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoicing\Models\EInvoice;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Providers\ProviderRegistry;
use App\Modules\Invoicing\Services\EInvoiceService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Hóa đơn điện tử: the shop's provider connection (Cấu hình → Kết nối dịch vụ) and the invoice of each order. */
class EInvoiceController extends Controller
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly EInvoiceService $service) {}

    public function settings(): JsonResponse
    {
        abort_unless(app(TenantContext::class)->can('settings.manage') || app(TenantContext::class)->can('einvoice.issue'), 403);
        $available = array_map(fn (string $key) => [
            'provider' => $key,
            'label' => $this->providers->get($key)->label(),
            'fields' => $this->providers->get($key)->credentialFields(),
            'available' => true,
            'note' => null,
        ], $this->providers->keys());
        $upcoming = collect(ProviderRegistry::UPCOMING)->map(fn ($p, $key) => ['provider' => $key, 'label' => $p['label'], 'fields' => [], 'available' => false, 'note' => $p['note']])->values()->all();

        return response()->json(['data' => ['providers' => [...$available, ...$upcoming], 'account' => $this->presentAccount($this->service->account())]]);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->manage();
        $data = $request->validate([
            'provider' => ['required', Rule::in($this->providers->keys())],
            'environment' => ['required', Rule::in([EInvoiceAccount::PRODUCTION, EInvoiceAccount::SANDBOX])],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);
        $fields = array_keys($this->providers->get($data['provider'])->credentialFields());
        $credentials = array_map('trim', array_intersect_key(array_filter($data['credentials'], fn ($v) => $v !== null), array_flip($fields)));

        return response()->json(['data' => $this->presentAccount($this->service->connect($data['provider'], $data['environment'], $credentials))]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->manage();
        $account = $this->service->account();
        abort_if($account === null, 404);
        $data = $request->validate([
            'template' => ['sometimes', 'string', 'max:40'],
            'issue_mode' => ['sometimes', Rule::in(EInvoiceAccount::MODES)],
            'send_email' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->presentAccount($this->service->updateSettings($account, $data))]);
    }

    public function disconnect(): JsonResponse
    {
        $this->manage();
        $account = $this->service->account();
        abort_if($account === null, 404);
        $this->service->disconnect($account);

        return response()->json(['data' => $this->presentAccount($account->refresh())]);
    }

    public function forOrder(Order $order): JsonResponse
    {
        $this->authorize('view', $order);
        $account = $this->service->account();

        return response()->json(['data' => [
            'ready' => (bool) $account?->isUsable(),
            'template' => $account?->isUsable() ? $this->templateLabel($account) : null,
            'buyer' => $this->service->buyerFor($order),
            'invoices' => EInvoice::query()->where('order_id', $order->id)->latest('id')->get()->map(fn (EInvoice $i) => EInvoiceService::present($i)),
        ]]);
    }

    public function issue(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);
        abort_unless(app(TenantContext::class)->can('einvoice.issue'), 403);
        $buyer = $request->validate([
            'buyer' => ['nullable', 'array'],
            'buyer.name' => ['required_with:buyer', 'string', 'max:255'],
            'buyer.legal_name' => ['nullable', 'string', 'max:255'],
            'buyer.tax_code' => ['nullable', 'string', 'regex:/^\d{10}(-\d{3})?$|^\d{12}$/'],
            'buyer.address' => ['nullable', 'string', 'max:500'],
            'buyer.email' => ['nullable', 'email', 'max:150'],
            'buyer.phone' => ['nullable', 'string', 'max:20'],
        ], ['buyer.tax_code.regex' => 'Mã số thuế gồm 10 số (chi nhánh: 10 số-3 số) hoặc 12 số.'])['buyer'] ?? null;

        return response()->json(['data' => EInvoiceService::present($this->service->issue($order, $buyer))], 201);
    }

    public function refresh(EInvoice $einvoice): JsonResponse
    {
        $this->authorize('view', $einvoice->order);

        return response()->json(['data' => EInvoiceService::present($this->service->refresh($einvoice))]);
    }

    private function manage(): void
    {
        abort_unless(app(TenantContext::class)->can('settings.manage'), 403);
    }

    private function templateLabel(EInvoiceAccount $account): string
    {
        $t = collect($account->templates ?? [])->first(fn ($t) => $t['template_code'] === $account->template_code && $t['invoice_series'] === $account->invoice_series);

        return $t['label'] ?? "{$account->template_code} - {$account->invoice_series}";
    }

    /** @return array<string, mixed>|null */
    private function presentAccount(?EInvoiceAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => $account->ulid,
            'provider' => $account->provider,
            'provider_label' => $this->providers->has($account->provider) ? $this->providers->get($account->provider)->label() : $account->provider,
            'provider_name' => $account->provider_name,
            'environment' => $account->environment,
            'status' => $account->status,
            'templates' => $account->templates ?? [],
            'template' => $account->template_code ? "{$account->template_code}|{$account->invoice_series}" : null,
            'template_label' => $account->template_code ? $this->templateLabel($account) : null,
            'issue_mode' => $account->issue_mode,
            'send_email' => $account->send_email,
            'last_error' => $account->last_error,
            'last_verified_at' => $account->last_verified_at?->toIso8601String(),
            // Which credential fields are filled, never their values.
            'credentials_set' => array_keys(array_filter((array) $account->credentials)),
        ];
    }
}
