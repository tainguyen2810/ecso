<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;

/**
 * Business configuration ("Cấu hình", like Sapo's settings), stored as JSON on the tenant. Every key has a default,
 * so a tenant that never opened the page behaves exactly as before.
 */
class TenantSettings
{
    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'card', 'cod', 'ewallet'];

    public const PRINT_DOCUMENTS = ['sale', 'purchase', 'receipt', 'stocktake', 'cash'];

    public const PRINT_SIZES = ['a4', 'a5', 'k80', 'k57'];

    public const DEFAULTS = [
        'store' => ['phone' => null, 'email' => null, 'website' => null, 'tax_code' => null, 'business_type' => null, 'address' => null, 'province' => null, 'ward' => null, 'province_code' => null, 'ward_code' => null],
        // A2: answers from the setup steps. channels: shopee, tiktok, woocommerce, pos, social, lazada_tiki.
        'setup' => ['industry' => null, 'channels' => null, 'team_size' => null],
        // Menu: null = automatic (basic for a small shop, full from a second warehouse or 6 members), true/false = chosen.
        'interface' => ['advanced_menu' => null],
        'tax' => ['sale_rate' => 0, 'purchase_rate' => 0, 'prices_include_tax' => true],
        'pricing' => ['sale_price' => 'retail', 'staff_edit_price' => true],
        'payments' => [
            'methods' => ['cash' => true, 'bank_transfer' => true, 'card' => true, 'cod' => true, 'ewallet' => true],
            'default_pos' => 'cash',
            'default_online' => 'bank_transfer',
            'bank_accounts' => [],
            // Payment vouchers of at least this amount wait for approval (finance.approve) before they count. 0: off.
            'approval_threshold' => 0,
        ],
        'sales' => ['allow_change_sale_time' => false, 'invoice_copies' => 1, 'pos_require_cash' => false, 'pos_auto_print' => true, 'pos_require_shift' => true, 'marketplace_auto_arrange' => false],
        'reasons' => [
            ['label' => 'Khách đổi ý', 'cancel' => true, 'return' => true],
            ['label' => 'Hết hàng', 'cancel' => true, 'return' => false],
            ['label' => 'Khách không nhận hàng', 'cancel' => true, 'return' => true],
            ['label' => 'Hàng lỗi, hỏng', 'cancel' => false, 'return' => true],
            ['label' => 'Giao sai mẫu', 'cancel' => false, 'return' => true],
        ],
        // Tích điểm (C5): 1 point per earn_per đồng paid (shipping left out), each point worth point_value đồng when
        // spent, at most max_redeem_percent of the order; points expire after expire_months (0 = never).
        'loyalty' => ['enabled' => false, 'earn_per' => 100000, 'point_value' => 1000, 'max_redeem_percent' => 50, 'expire_months' => 12, 'with_promotions' => true],
        'print' => [
            'defaults' => ['sale' => 'a5', 'purchase' => 'a4', 'receipt' => 'a4', 'stocktake' => 'a4', 'cash' => 'a5'],
            'show_logo' => true,
            'show_qr' => true,
            'show_signatures' => true,
            'footer' => 'Cảm ơn quý khách!',
        ],
    ];

    /**
     * Whether the sidebar shows the advanced pages (Ca bán hàng, Chuyển hàng, Điều chỉnh giá vốn…). A small shop gets
     * the basic menu until it opens a second warehouse or has 6 members; the owner can switch either way.
     */
    public static function advancedMenu(Tenant $tenant): bool
    {
        $chosen = $tenant->settings['interface']['advanced_menu'] ?? null;
        if ($chosen !== null) {
            return (bool) $chosen;
        }
        if (in_array($tenant->settings['setup']['team_size'] ?? null, ['6-20', '20+'], true)) {
            return true;
        }
        $warehouses = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->where('status', 'active')->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', '!=', 'defective'))->count();
        if ($warehouses >= 2) {
            return true;
        }

        return TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'active')->count() >= 6;
    }

    /** @param  array<string, mixed>  $values */
    private function __construct(private array $values) {}

    public static function for(Tenant $tenant): self
    {
        return new self(self::merge(self::DEFAULTS, $tenant->settings ?? []));
    }

    public static function current(): self
    {
        return self::for(app(TenantContext::class)->tenant());
    }

    /** Dot path, e.g. "pricing.staff_edit_price". */
    public function get(string $key): mixed
    {
        return data_get($this->values, $key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Defaults overlaid with what was saved. Lists (reasons, bank accounts) replace the default list as a whole.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    public static function merge(array $defaults, array $saved): array
    {
        foreach ($saved as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }
            $default = $defaults[$key];
            if (is_array($default) && $default !== [] && ! array_is_list($default)) {
                if (is_array($value)) {
                    $defaults[$key] = self::merge($default, $value);
                }

                continue;
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }
}
