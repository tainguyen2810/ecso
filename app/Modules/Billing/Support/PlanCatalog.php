<?php

namespace App\Modules\Billing\Support;

/**
 * Default plans seeded at install time. Values are editable in the database afterwards;
 * application code reads entitlements, never plan keys (ADR-10). Prices are placeholders.
 */
final class PlanCatalog
{
    public const FEATURES = [
        'limit.users' => ['type' => 'limit', 'name' => 'Số người dùng'],
        'limit.shops' => ['type' => 'limit', 'name' => 'Số shop kết nối'],
        'limit.warehouses' => ['type' => 'limit', 'name' => 'Số kho'],
        'limit.orders_per_month' => ['type' => 'limit', 'name' => 'Số đơn mỗi tháng'],
        'limit.data_retention_days' => ['type' => 'limit', 'name' => 'Thời gian lưu dữ liệu (ngày)'],
        'reports.advanced' => ['type' => 'boolean', 'name' => 'Báo cáo nâng cao'],
        'automation.rules' => ['type' => 'boolean', 'name' => 'Tự động hóa'],
    ];

    /** null = unlimited */
    public const PLANS = [
        'free' => ['name' => 'Free', 'price_monthly' => 0, 'price_yearly' => 0, 'trial_days' => 0, 'sort' => 1, 'features' => [
            'limit.users' => 2, 'limit.shops' => 1, 'limit.warehouses' => 1, 'limit.orders_per_month' => 300,
            'limit.data_retention_days' => 90, 'reports.advanced' => false, 'automation.rules' => false,
        ]],
        'starter' => ['name' => 'Starter', 'price_monthly' => 199000, 'price_yearly' => 1990000, 'trial_days' => 14, 'sort' => 2, 'features' => [
            'limit.users' => 5, 'limit.shops' => 3, 'limit.warehouses' => 2, 'limit.orders_per_month' => 3000,
            'limit.data_retention_days' => 180, 'reports.advanced' => false, 'automation.rules' => false,
        ]],
        'pro' => ['name' => 'Pro', 'price_monthly' => 499000, 'price_yearly' => 4990000, 'trial_days' => 14, 'sort' => 3, 'features' => [
            'limit.users' => 15, 'limit.shops' => 10, 'limit.warehouses' => 5, 'limit.orders_per_month' => 20000,
            'limit.data_retention_days' => 365, 'reports.advanced' => true, 'automation.rules' => false,
        ]],
        'business' => ['name' => 'Business', 'price_monthly' => 1290000, 'price_yearly' => 12900000, 'trial_days' => 14, 'sort' => 4, 'features' => [
            'limit.users' => null, 'limit.shops' => null, 'limit.warehouses' => null, 'limit.orders_per_month' => null,
            'limit.data_retention_days' => 730, 'reports.advanced' => true, 'automation.rules' => true,
        ]],
    ];
}
