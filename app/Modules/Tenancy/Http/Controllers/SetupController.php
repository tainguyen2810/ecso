<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A2: the setup steps after sign-up. The store name, phone and address go through "tenant/settings/store" like the
 * settings page; this saves the other answers (industry, where the shop sells, team size) and marks setup done.
 * The answers only arrange suggestions (the "Bắt đầu" list, the POS menu); nothing is locked by them.
 */
class SetupController extends Controller
{
    public const INDUSTRIES = ['fashion', 'cosmetics', 'mom_baby', 'electronics', 'food', 'household', 'books', 'other'];

    public const CHANNELS = ['shopee', 'tiktok', 'woocommerce', 'pos', 'social', 'lazada_tiki'];

    public const TEAM_SIZES = ['1', '2-5', '6-20', '20+'];

    public function update(Request $request, TenantContext $context, AuditLogger $audit): TenantResource
    {
        $tenant = $context->tenant();
        $this->authorize('update', $tenant);
        $data = $request->validate([
            'industry' => ['sometimes', 'nullable', Rule::in(self::INDUSTRIES)],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', Rule::in(self::CHANNELS)],
            'team_size' => ['sometimes', 'nullable', Rule::in(self::TEAM_SIZES)],
            'complete' => ['sometimes', 'boolean'],
        ]);

        $complete = (bool) ($data['complete'] ?? false);
        unset($data['complete']);
        if (isset($data['channels'])) {
            $data['channels'] = array_values(array_unique($data['channels']));
        }

        $settings = $tenant->settings ?? [];
        $settings['setup'] = TenantSettings::merge(TenantSettings::merge(TenantSettings::DEFAULTS['setup'], $settings['setup'] ?? []), $data);
        $tenant->settings = $settings;
        if ($complete && $tenant->setup_completed_at === null) {
            $tenant->setup_completed_at = now();
        }
        $tenant->save();
        $this->fillWarehouseAddress($tenant->settings['store'] ?? []);
        $audit->record('tenant.setup_updated', $tenant, ['setup' => $settings['setup'], 'completed' => $complete]);

        return new TenantResource($tenant);
    }

    /**
     * The first warehouse takes the store address given in step 1 (where carriers pick up), unless it has one.
     *
     * @param  array<string, mixed>  $store
     */
    private function fillWarehouseAddress(array $store): void
    {
        $address = implode(', ', array_filter([$store['address'] ?? null, $store['ward'] ?? null, $store['province'] ?? null]));
        if ($address === '') {
            return;
        }
        Warehouse::query()->where('is_default', true)->where(fn ($q) => $q->whereNull('address')->orWhere('address', ''))
            ->update(['address' => mb_substr($address, 0, 255)]);
    }
}
