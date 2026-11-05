<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;

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
        'store' => ['phone' => null, 'email' => null, 'website' => null, 'tax_code' => null, 'business_type' => null, 'address' => null, 'province' => null, 'ward' => null],
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
        'sales' => ['allow_change_sale_time' => false, 'invoice_copies' => 1, 'pos_require_cash' => false, 'pos_auto_print' => true, 'pos_require_shift' => true],
        'reasons' => [
            ['label' => 'Khách đổi ý', 'cancel' => true, 'return' => true],
            ['label' => 'Hết hàng', 'cancel' => true, 'return' => false],
            ['label' => 'Khách không nhận hàng', 'cancel' => true, 'return' => true],
            ['label' => 'Hàng lỗi, hỏng', 'cancel' => false, 'return' => true],
            ['label' => 'Giao sai mẫu', 'cancel' => false, 'return' => true],
        ],
        'print' => [
            'defaults' => ['sale' => 'a5', 'purchase' => 'a4', 'receipt' => 'a4', 'stocktake' => 'a4', 'cash' => 'a5'],
            'show_logo' => true,
            'show_qr' => true,
            'show_signatures' => true,
            'footer' => 'Cảm ơn quý khách!',
        ],
    ];

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
