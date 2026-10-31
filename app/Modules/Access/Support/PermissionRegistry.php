<?php

namespace App\Modules\Access\Support;

use App\Modules\Access\Models\Role;

/**
 * Single source of truth for permission keys and default system roles.
 * Business code checks permission keys only, never role names (ADR-10).
 */
final class PermissionRegistry
{
    /** @var array<string, array<string, string>> module => [key => description] */
    public const PERMISSIONS = [
        'dashboard' => ['dashboard.view' => 'Xem tổng quan'],
        'orders' => [
            'orders.view' => 'Xem đơn hàng',
            'orders.update' => 'Xử lý đơn hàng',
            'orders.assign' => 'Phân công đơn',
            'orders.print' => 'In phiếu đóng gói, vận đơn',
        ],
        'products' => [
            'products.view' => 'Xem sản phẩm',
            'products.manage' => 'Quản lý sản phẩm và mapping',
        ],
        'inventory' => [
            'inventory.view' => 'Xem tồn kho',
            'inventory.receive' => 'Nhập kho',
            'inventory.adjust' => 'Tạo phiếu điều chỉnh kho',
            'inventory.adjust.approve' => 'Duyệt điều chỉnh kho',
            'inventory.transfer' => 'Chuyển kho',
        ],
        'purchasing' => [
            'purchasing.view' => 'Xem mua hàng',
            'purchasing.manage' => 'Quản lý mua hàng, nhập kho',
        ],
        'returns' => [
            'returns.view' => 'Xem trả hàng',
            'returns.inspect' => 'Kiểm và nhập lại hàng hoàn',
        ],
        'finance' => ['finance.view' => 'Xem tài chính'],
        'reports' => ['reports.view' => 'Xem báo cáo'],
        'marketplace' => [
            'marketplace.view' => 'Xem kết nối sàn',
            'marketplace.connect' => 'Kết nối, ngắt kết nối sàn',
        ],
        'members' => [
            'members.view' => 'Xem nhân viên',
            'members.manage' => 'Mời, phân quyền nhân viên',
        ],
        'billing' => [
            'billing.view' => 'Xem gói dịch vụ, hóa đơn',
            'billing.manage' => 'Đổi gói, thanh toán',
        ],
        'settings' => ['settings.manage' => 'Cài đặt doanh nghiệp'],
        'audit' => ['audit.view' => 'Xem nhật ký thao tác'],
    ];

    private const READ_ALL = [
        'dashboard.view', 'orders.view', 'products.view', 'inventory.view', 'purchasing.view',
        'returns.view', 'reports.view', 'marketplace.view',
    ];

    /**
     * @return array<string, array{name: string, rank: int, permissions: array<int, string>}>
     */
    public static function systemRoles(): array
    {
        $all = self::allKeys();

        return [
            Role::OWNER => ['name' => 'Chủ doanh nghiệp', 'rank' => 100, 'permissions' => $all],
            Role::ADMIN => ['name' => 'Quản trị doanh nghiệp', 'rank' => 90, 'permissions' => array_values(array_diff($all, ['billing.manage']))],
            Role::MANAGER => ['name' => 'Quản lý vận hành', 'rank' => 70, 'permissions' => [
                ...self::READ_ALL, 'orders.update', 'orders.assign', 'orders.print', 'products.manage',
                'inventory.receive', 'inventory.adjust', 'inventory.adjust.approve', 'inventory.transfer', 'purchasing.manage',
                'returns.inspect', 'finance.view', 'members.view',
            ]],
            Role::WAREHOUSE_STAFF => ['name' => 'Nhân viên kho', 'rank' => 40, 'permissions' => [
                'dashboard.view', 'orders.view', 'orders.print', 'products.view', 'inventory.view',
                'inventory.receive', 'inventory.adjust', 'inventory.transfer', 'purchasing.view', 'returns.view', 'returns.inspect',
            ]],
            Role::ORDER_STAFF => ['name' => 'Nhân viên xử lý đơn', 'rank' => 40, 'permissions' => [
                'dashboard.view', 'orders.view', 'orders.update', 'orders.print', 'products.view',
                'inventory.view', 'returns.view',
            ]],
            Role::ACCOUNTANT => ['name' => 'Kế toán', 'rank' => 40, 'permissions' => [
                'dashboard.view', 'orders.view', 'products.view', 'inventory.view', 'purchasing.view',
                'returns.view', 'finance.view', 'reports.view', 'billing.view',
            ]],
            Role::VIEWER => ['name' => 'Chỉ xem', 'rank' => 10, 'permissions' => self::READ_ALL],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allKeys(): array
    {
        return array_keys(array_merge(...array_values(self::PERMISSIONS)));
    }
}
