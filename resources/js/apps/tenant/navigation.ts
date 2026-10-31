import {
    BarChart3,
    Boxes,
    CreditCard,
    LayoutDashboard,
    Package,
    PlugZap,
    ReceiptText,
    ScrollText,
    Settings,
    ShoppingCart,
    Truck,
    Undo2,
    Users,
    Wallet,
} from 'lucide-vue-next';
import type { Component } from 'vue';

export interface NavItem {
    name: string;
    label: string;
    path: string;
    icon: Component;
    permission: string;
    /** Phase that delivers the module; undefined = available now. */
    phase?: number;
    shortcut?: string;
    description?: string;
}

export const navGroups: { label: string; items: NavItem[] }[] = [
    {
        label: 'Vận hành',
        items: [
            { name: 'dashboard', label: 'Tổng quan', path: '/', icon: LayoutDashboard, permission: 'dashboard.view', shortcut: 'd' },
            { name: 'orders', label: 'Đơn hàng', path: '/orders', icon: ShoppingCart, permission: 'orders.view', shortcut: 'o', description: 'Quản lý đơn tập trung từ mọi sàn: lọc, xử lý hàng loạt, in phiếu, theo dõi trạng thái.' },
            { name: 'products', label: 'Sản phẩm', path: '/products', icon: Package, permission: 'products.view', shortcut: 'p', description: 'SKU, biến thể, mã vạch, mapping với listing trên sàn.' },
            { name: 'inventory', label: 'Kho hàng', path: '/inventory', icon: Boxes, permission: 'inventory.view', shortcut: 'i', description: 'Tồn kho nhiều kho, sổ kho, giữ tồn, điều chỉnh và chuyển kho.' },
            { name: 'purchasing', label: 'Mua hàng', path: '/purchasing', icon: Truck, permission: 'purchasing.view', phase: 6, description: 'Nhà cung cấp, đơn mua, phiếu nhập và giá vốn.' },
            { name: 'returns', label: 'Trả hàng', path: '/returns', icon: Undo2, permission: 'returns.view', description: 'Hàng hoàn từ khách, kiểm hàng và nhập kho lại.' },
        ],
    },
    {
        label: 'Phân tích',
        items: [
            { name: 'finance', label: 'Tài chính', path: '/finance', icon: Wallet, permission: 'finance.view', phase: 6, description: 'Doanh thu, phí sàn, đối soát, lợi nhuận ước tính và đã xác nhận.' },
            { name: 'reports', label: 'Báo cáo', path: '/reports', icon: BarChart3, permission: 'reports.view', phase: 6, description: 'Sản phẩm bán chạy, bán chậm, giá trị tồn kho.' },
        ],
    },
    {
        label: 'Thiết lập',
        items: [
            { name: 'marketplaces', label: 'Kết nối sàn', path: '/marketplaces', icon: PlugZap, permission: 'marketplace.view', description: 'Kết nối Shopee, TikTok Shop, Lazada, Tiki bằng API chính thức.' },
            { name: 'members', label: 'Nhân viên', path: '/members', icon: Users, permission: 'members.view', shortcut: 'm' },
            { name: 'subscription', label: 'Gói dịch vụ', path: '/subscription', icon: CreditCard, permission: 'billing.view' },
            { name: 'audit', label: 'Nhật ký', path: '/audit', icon: ScrollText, permission: 'audit.view' },
            { name: 'settings', label: 'Cài đặt', path: '/settings', icon: Settings, permission: 'settings.manage', shortcut: 's' },
        ],
    },
];

export const allNavItems = navGroups.flatMap((g) => g.items);
export const placeholderIcon = ReceiptText;
