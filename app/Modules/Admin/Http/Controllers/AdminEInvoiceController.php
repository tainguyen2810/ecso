<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoicing\Models\EInvoiceAccount;
use App\Modules\Invoicing\Models\EInvoiceOrder;
use App\Modules\Invoicing\Models\EInvoicePackage;
use App\Modules\Invoicing\Models\EInvoiceProviderRequest;
use App\Modules\Invoicing\Providers\ProviderRegistry;
use App\Modules\Invoicing\Sales\EInvoiceSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Bán hóa đơn: the packages we sell as a reseller and the shops' orders. Payment is confirmed against the
 * bank statement, then each registration step is moved here; the last step connects the provider account for the shop.
 */
class AdminEInvoiceController extends Controller
{
    public function __construct(private readonly EInvoiceSales $sales, private readonly ProviderRegistry $providers) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:50']]);
        $orders = EInvoiceOrder::withoutGlobalScopes()->with('tenant:id,ulid,name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'open' ? $q->whereNotIn('status', [EInvoiceOrder::CONNECTED, EInvoiceOrder::CANCELLED]) : $q->where('status', $s))
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('transfer_code', $t)
                ->orWhere('company->tax_code', $t)->orWhereHas('tenant', fn ($x) => $x->where('name', 'like', "%{$t}%"))))
            ->latest('id')->limit(200)->get();
        $month = EInvoiceOrder::withoutGlobalScopes()->whereNotNull('paid_at')->where('paid_at', '>=', now()->startOfMonth());

        return response()->json(['data' => [
            'orders' => $orders->map(fn ($o) => EInvoiceSales::present($o, true)),
            'packages' => EInvoicePackage::query()->orderBy('sort')->orderBy('invoices')->get()->map(fn ($p) => EInvoiceSales::presentPackage($p)),
            'requests' => EInvoiceProviderRequest::withoutGlobalScopes()->selectRaw('provider, COUNT(DISTINCT tenant_id) as shops, MAX(created_at) as last_at')->groupBy('provider')->orderByDesc('shops')->limit(20)->get(),
            'summary' => [
                'orders_month' => EInvoiceOrder::withoutGlobalScopes()->where('created_at', '>=', now()->startOfMonth())->where('status', '!=', EInvoiceOrder::CANCELLED)->count(),
                'connected_month' => EInvoiceOrder::withoutGlobalScopes()->where('status', EInvoiceOrder::CONNECTED)->where('updated_at', '>=', now()->startOfMonth())->count(),
                'revenue_month' => (float) (clone $month)->sum('amount'),
                'open' => EInvoiceOrder::withoutGlobalScopes()->whereNotIn('status', [EInvoiceOrder::CONNECTED, EInvoiceOrder::CANCELLED])->count(),
                'stale' => EInvoiceOrder::withoutGlobalScopes()->whereNotIn('status', [EInvoiceOrder::CONNECTED, EInvoiceOrder::CANCELLED, EInvoiceOrder::AWAITING_PAYMENT])->where('updated_at', '<', now()->subDays(2))->count(),
            ],
            'providers' => collect($this->providers->keys())->reject(fn ($k) => $k === 'fake')->map(fn ($k) => ['provider' => $k, 'label' => $this->providers->get($k)->label(), 'fields' => $this->providers->get($k)->credentialFields()])->values(),
        ]]);
    }

    public function storePackage(Request $request): JsonResponse
    {
        $package = EInvoicePackage::query()->create($this->packageData($request));

        return response()->json(['data' => EInvoiceSales::presentPackage($package)], 201);
    }

    public function updatePackage(Request $request, string $package): JsonResponse
    {
        $model = EInvoicePackage::query()->where('ulid', $package)->firstOrFail();
        $model->fill($this->packageData($request))->save();

        return response()->json(['data' => EInvoiceSales::presentPackage($model)]);
    }

    public function destroyPackage(string $package): JsonResponse
    {
        $model = EInvoicePackage::query()->where('ulid', $package)->firstOrFail();
        // Sold packages stay (orders keep their own copy of the price) but are no longer offered.
        EInvoiceOrder::withoutGlobalScopes()->where('package_id', $model->id)->exists() ? $model->forceFill(['active' => false])->save() : $model->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function confirmPayment(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return $this->row($this->sales->confirmPayment($this->order($order), trim($data['reference'])));
    }

    public function advance(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([EInvoiceOrder::ACCOUNT_CREATED, EInvoiceOrder::WAITING_SIGNATURE, EInvoiceOrder::TAX_APPROVED])],
            'shop_note' => ['nullable', 'string', 'max:500'],
            'sign_url' => ['nullable', 'url:https', 'max:500'],
        ]);

        return $this->row($this->sales->advance($this->order($order), $data['status'], ['shop_note' => $data['shop_note'] ?? null, 'sign_url' => $data['sign_url'] ?? null]));
    }

    public function connect(Request $request, string $order): JsonResponse
    {
        $model = $this->order($order);
        $data = $request->validate([
            'environment' => ['required', Rule::in([EInvoiceAccount::PRODUCTION, EInvoiceAccount::SANDBOX])],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless($this->providers->has($model->provider), 422, 'Nhà cung cấp của gói này chưa kết nối được.');
        $fields = array_keys($this->providers->get($model->provider)->credentialFields());
        $credentials = array_map('trim', array_intersect_key(array_filter($data['credentials'], fn ($v) => $v !== null), array_flip($fields)));

        return $this->row($this->sales->connect($model, $data['environment'], $credentials));
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->row($this->sales->cancelByAdmin($this->order($order), $data['reason']));
    }

    private function order(string $ulid): EInvoiceOrder
    {
        return EInvoiceOrder::withoutGlobalScopes()->with('tenant:id,ulid,name')->where('ulid', $ulid)->firstOrFail();
    }

    private function row(EInvoiceOrder $order): JsonResponse
    {
        return response()->json(['data' => EInvoiceSales::present($order->loadMissing('tenant:id,ulid,name'), true)]);
    }

    /** @return array<string, mixed> */
    private function packageData(Request $request): array
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['misa', 'viettel', 'vnpt', 'sepay'])],
            'name' => ['required', 'string', 'max:80'],
            'invoices' => ['required', 'integer', 'min:1', 'max:10000000'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'setup_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'signing_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
            'featured' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        $data['setup_fee'] ??= 0;
        $data['sort'] ??= 0;

        return $data;
    }
}
