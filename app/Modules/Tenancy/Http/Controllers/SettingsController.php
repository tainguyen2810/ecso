<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Cấu hình": every member reads the settings (the order form, POS and printouts need them); only members with
 * settings.manage change them, one section at a time.
 */
class SettingsController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        return $this->respond($context);
    }

    public function update(Request $request, string $section, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorize('update', $tenant);
        $rules = $this->rules()[$section] ?? abort(404);
        $data = $request->validate($rules);

        if ($section === 'payments') {
            $this->assertPayments($data);
        }
        if ($section === 'store' && isset($data['name'])) {
            $tenant->name = $data['name'];
            unset($data['name']);
        }
        if ($section === 'store' && isset($data['timezone'])) {
            $tenant->timezone = $data['timezone'];
            unset($data['timezone']);
        }

        $settings = $tenant->settings ?? [];
        // Lists are saved whole; other sections keep the keys that were not sent.
        $settings[$section] = in_array($section, ['reasons'], true)
            ? array_values($data['reasons'])
            : TenantSettings::merge(TenantSettings::merge(TenantSettings::DEFAULTS[$section], $settings[$section] ?? []), $data);
        $tenant->settings = $settings;
        $tenant->save();
        $audit->record('tenant.settings_updated', $tenant, ['section' => $section]);

        return $this->respond($context);
    }

    public function uploadLogo(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorize('update', $tenant);
        // No SVG: a user-supplied SVG can carry script.
        $request->validate(['logo' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp', 'dimensions:max_width=3000,max_height=3000']]);

        $file = $request->file('logo');
        $path = $file->storeAs("tenants/{$tenant->ulid}/brand", 'logo-'.Str::random(16).'.'.$file->extension(), 'public');
        if ($tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
        }
        $tenant->logo_path = $path;
        $tenant->save();

        return $this->respond($context);
    }

    public function deleteLogo(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorize('update', $tenant);
        if ($tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
            $tenant->logo_path = null;
            $tenant->save();
        }

        return $this->respond($context);
    }

    private function respond(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();

        return response()->json(['data' => [
            'name' => $tenant->name,
            'timezone' => $tenant->timezone,
            'logo_url' => $tenant->logoUrl(),
            ...TenantSettings::for($tenant)->all(),
        ]]);
    }

    /** @param  array<string, mixed>  $data */
    private function assertPayments(array $data): void
    {
        $methods = TenantSettings::merge(TenantSettings::DEFAULTS['payments']['methods'], $data['methods'] ?? TenantSettings::current()->get('payments.methods'));
        foreach (['default_pos', 'default_online'] as $key) {
            $method = $data[$key] ?? TenantSettings::current()->get("payments.{$key}");
            if (! ($methods[$method] ?? false)) {
                throw ValidationException::withMessages([$key => 'Phương thức mặc định phải đang được bật.']);
            }
        }
        $accounts = $data['bank_accounts'] ?? [];
        if ($accounts !== [] && count(array_filter($accounts, fn ($a) => $a['is_default'] ?? false)) > 1) {
            throw ValidationException::withMessages(['bank_accounts' => 'Chỉ một tài khoản mặc định.']);
        }
    }

    /** @return array<string, array<string, array<int, mixed>>> */
    private function rules(): array
    {
        $method = Rule::in(TenantSettings::PAYMENT_METHODS);
        $size = Rule::in(TenantSettings::PRINT_SIZES);

        return [
            'store' => [
                'name' => ['sometimes', 'string', 'min:2', 'max:120'],
                'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
                'phone' => ['nullable', 'string', 'max:20'],
                'email' => ['nullable', 'email', 'max:150'],
                'website' => ['nullable', 'string', 'max:150'],
                'tax_code' => ['nullable', 'string', 'max:20'],
                'business_type' => ['nullable', 'string', 'max:100'],
                'address' => ['nullable', 'string', 'max:255'],
                'province' => ['nullable', 'string', 'max:100'],
                'ward' => ['nullable', 'string', 'max:100'],
            ],
            'tax' => [
                'sale_rate' => ['sometimes', 'numeric', Rule::in([0, 5, 8, 10])],
                'purchase_rate' => ['sometimes', 'numeric', Rule::in([0, 5, 8, 10])],
                'prices_include_tax' => ['sometimes', 'boolean'],
            ],
            'pricing' => [
                'sale_price' => ['sometimes', Rule::in(['retail', 'wholesale'])],
                'staff_edit_price' => ['sometimes', 'boolean'],
            ],
            'payments' => [
                'methods' => ['sometimes', 'array'],
                ...collect(TenantSettings::PAYMENT_METHODS)->mapWithKeys(fn ($m) => ["methods.{$m}" => ['boolean']])->all(),
                'default_pos' => ['sometimes', $method],
                'default_online' => ['sometimes', $method],
                'bank_accounts' => ['sometimes', 'array', 'max:5'],
                'bank_accounts.*.bank' => ['required', 'string', 'max:20'],
                'bank_accounts.*.number' => ['required', 'string', 'regex:/^[0-9]{6,20}$/'],
                'bank_accounts.*.holder' => ['required', 'string', 'max:100'],
                'bank_accounts.*.is_default' => ['boolean'],
                'approval_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            ],
            'sales' => [
                'allow_change_sale_time' => ['sometimes', 'boolean'],
                'invoice_copies' => ['sometimes', 'integer', 'between:1,3'],
                'pos_require_cash' => ['sometimes', 'boolean'],
                'pos_require_shift' => ['sometimes', 'boolean'],
                'pos_auto_print' => ['sometimes', 'boolean'],
            ],
            'reasons' => [
                'reasons' => ['present', 'array', 'max:30'],
                'reasons.*.label' => ['required', 'string', 'max:100'],
                'reasons.*.cancel' => ['boolean'],
                'reasons.*.return' => ['boolean'],
            ],
            'print' => [
                'defaults' => ['sometimes', 'array'],
                ...collect(TenantSettings::PRINT_DOCUMENTS)->mapWithKeys(fn ($d) => ["defaults.{$d}" => [$size]])->all(),
                'show_logo' => ['sometimes', 'boolean'],
                'show_qr' => ['sometimes', 'boolean'],
                'show_signatures' => ['sometimes', 'boolean'],
                'footer' => ['nullable', 'string', 'max:255'],
            ],
        ];
    }
}
