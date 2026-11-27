<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCodes;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cấu hình › Mã đơn hàng: the prefix and next number of the shop's order codes, and the one-time start that numbers
 * the orders already in ECSO. Every member reads it; settings.manage (tenant update) changes it.
 */
class OrderCodeController extends Controller
{
    public function __construct(private readonly OrderCodes $codes) {}

    public function show(TenantContext $context): JsonResponse
    {
        return $this->respond($context);
    }

    public function update(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorize('update', $tenant);
        $prefix = ['string', 'regex:'.OrderCodes::PREFIX_PATTERN];
        $request->merge([
            'prefix' => strtoupper(trim((string) $request->input('prefix'))),
            'prefixes' => array_map(fn ($p) => is_string($p) && trim($p) !== '' ? strtoupper(trim($p)) : null, (array) $request->input('prefixes', [])),
        ]);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['shared', 'per_source'])],
            'prefix' => ['required', ...$prefix],
            'next' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
            'prefixes' => ['nullable', 'array'],
            ...collect(OrderCodes::SOURCES)->mapWithKeys(fn ($s) => ["prefixes.{$s}" => ['nullable', ...$prefix]])->all(),
            'next_by_prefix' => ['nullable', 'array'],
            'next_by_prefix.*' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
        ], ['prefix.regex' => 'Kí hiệu chỉ gồm 1-6 chữ in hoa không dấu (VD: SON).', 'prefixes.*.regex' => 'Kí hiệu chỉ gồm 1-6 chữ in hoa không dấu.']);

        DB::transaction(function () use ($tenant, $data) {
            $settings = $tenant->settings ?? [];
            $prefixes = [];
            foreach (OrderCodes::SOURCES as $source) {
                $value = strtoupper(trim((string) ($data['prefixes'][$source] ?? '')));
                $prefixes[$source] = $value !== '' ? $value : null;
            }
            $settings['order_codes'] = array_merge($settings['order_codes'] ?? [], ['mode' => $data['mode'], 'prefix' => $data['prefix'], 'prefixes' => $prefixes]);
            $tenant->settings = $settings;
            $tenant->save();

            if (! empty($data['next'])) {
                $this->codes->setNext($tenant->id, $data['prefix'], (int) $data['next']);
            }
            foreach ($data['next_by_prefix'] ?? [] as $prefix => $next) {
                if ($next && preg_match(OrderCodes::PREFIX_PATTERN, (string) $prefix)) {
                    $this->codes->setNext($tenant->id, (string) $prefix, (int) $next);
                }
            }
        });
        $audit->record('tenant.settings_updated', $tenant, ['section' => 'order_codes']);

        return $this->respond($context);
    }

    /** "Bắt đầu dùng mã mới": numbers the orders without a code (oldest first) and turns the numbering on. */
    public function start(TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorize('update', $tenant);
        if (OrderCodes::enabled($tenant)) {
            throw ValidationException::withMessages(['start' => 'Cửa hàng đã dùng mã đơn mới rồi.']);
        }
        $result = $this->codes->start($tenant);
        $audit->record('orders.codes_started', $tenant, $result);

        return $this->respond($context);
    }

    private function respond(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $settings = TenantSettings::for($tenant)->get('order_codes');
        $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id);
        $prefixes = array_values(array_unique(array_filter([$settings['prefix'], ...array_values($settings['prefixes'] ?? [])])));

        return response()->json(['data' => [
            ...$settings,
            'next' => collect($prefixes)->mapWithKeys(fn ($p) => [$p => $this->codes->peek($tenant->id, $p)])->all(),
            'counts' => [
                'imported' => (clone $orders)->whereNotNull('imported_from')->count(),
                'imported_with_code' => (clone $orders)->whereNotNull('imported_from')->whereNotNull('code')->count(),
                'manual' => (clone $orders)->whereNull('imported_from')->whereNull('code')->where('marketplace', Order::SOURCE_MANUAL)->count(),
                'marketplace' => (clone $orders)->whereNull('code')->where('marketplace', '!=', Order::SOURCE_MANUAL)->count(),
                'without_code' => (clone $orders)->whereNull('code')->count(),
            ],
            // Highest number already used per prefix (Sapo's SON50891): the counter starts after it.
            'highest' => collect($prefixes)->mapWithKeys(fn ($p) => [$p => $this->codes->highestUsed($tenant->id, $p)])->all(),
        ]]);
    }
}
