<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoicing\Models\EInvoiceOrder;
use App\Modules\Invoicing\Models\EInvoicePackage;
use App\Modules\Invoicing\Models\EInvoiceProviderRequest;
use App\Modules\Invoicing\Sales\EInvoiceSales;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** "Mua gói hóa đơn" and "Gửi yêu cầu" another provider, from Cấu hình → Kết nối dịch vụ. Owners/admins only. */
class EInvoiceSalesController extends Controller
{
    public function __construct(private readonly EInvoiceSales $sales) {}

    public function index(): JsonResponse
    {
        $this->manage();
        $current = $this->sales->current();

        return response()->json(['data' => [
            'packages' => EInvoicePackage::query()->where('active', true)->orderBy('sort')->orderBy('invoices')->get()->map(fn ($p) => EInvoiceSales::presentPackage($p)),
            'order' => $current ? EInvoiceSales::present($current) : null,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->manage();
        $data = $request->validate([
            'package' => ['required', 'string', 'size:26'],
            'signing' => ['boolean'],
            'company.type' => ['required', 'in:company,household'],
            'company.tax_code' => ['required', 'string', 'regex:/^\d{10}(-\d{3})?$|^\d{12}$/'],
            'company.name' => ['required', 'string', 'max:255'],
            'company.address' => ['nullable', 'string', 'max:500'],
            'company.representative' => ['required', 'string', 'max:120'],
            'company.email' => ['required', 'email', 'max:150'],
            'company.phone' => ['required', 'string', 'max:20'],
        ], ['company.tax_code.regex' => 'Mã số thuế gồm 10 số (chi nhánh: 10 số-3 số) hoặc 12 số (hộ kinh doanh).']);
        $order = $this->sales->order(['package' => $data['package'], 'signing' => (bool) ($data['signing'] ?? false), 'company' => $data['company']]);

        return response()->json(['data' => EInvoiceSales::present($order)], 201);
    }

    public function cancel(EInvoiceOrder $einvoiceOrder): JsonResponse
    {
        $this->manage();

        return response()->json(['data' => EInvoiceSales::present($this->sales->cancelByShop($einvoiceOrder))]);
    }

    public function requestProvider(Request $request): JsonResponse
    {
        $this->manage();
        $data = $request->validate(['provider' => ['required', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255']]);
        EInvoiceProviderRequest::query()->create($data + ['created_by' => Auth::id()]);

        return response()->json(['data' => ['ok' => true]], 201);
    }

    private function manage(): void
    {
        abort_unless(app(TenantContext::class)->can('settings.manage'), 403);
    }
}
