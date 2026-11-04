import {
    BarChart3,
    CreditCard,
    HandCoins,
    LayoutDashboard,
    Package,
    PlugZap,
    ReceiptText,
    ScrollText,
    Settings,
    Store,
    UsersRound,
    ShoppingCart,
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
    /** Phase that delivers the module; undefined = available now; 9 = after MVP (not scheduled). */
    phase?: number;
    shortcut?: string;
    description?: string;
    /** Sub-menu shown under the item when it is open (like Sapo's sidebar). */
    children?: NavChild[];
    /** Not in the sidebar (reached from Cấu hình) but keeps its shortcut and its pages' highlight. */
    hidden?: boolean;
    /** Also shown to members who hold any of these (a group that gathers several modules). */
    anyPermission?: string[];
    /** Other route `meta.nav` values that belong to this item (e.g. returns under orders). */
    alsoActive?: string[];
}

export interface NavChild {
    label: string;
    path: string;
    permission?: string;
}

export const navGroups: { label: string; items: NavItem[] }[] = [
    {
        label: 'Vận hành',
        items: [
            { name: 'dashboard', label: 'Tổng quan', path: '/', icon: LayoutDashboard, permission: 'dashboard.view', shortcut: 'd' },
            { name: 'orders', label: 'Đơn hàng', path: '/orders', icon: ShoppingCart, permission: 'orders.view', shortcut: 'o', description: 'Quản lý đơn tập trung từ mọi sàn: lọc, xử lý hàng loạt, in phiếu, theo dõi trạng thái.',
                alsoActive: ['returns', 'pos'],
                children: [
                    { label: 'Tạo đơn và giao hàng', path: '/orders/new', permission: 'orders.create' },
                    { label: 'Danh sách đơn hàng', path: '/orders' },
                    { label: 'Bán tại quầy', path: '/pos', permission: 'orders.create' },
                    { label: 'Ca bán hàng', path: '/pos/shifts', permission: 'orders.create' },
                    { label: 'Khách trả hàng', path: '/returns', permission: 'returns.view' },
                ] },
            { name: 'customers', label: 'Khách hàng', path: '/customers', icon: UsersRound, permission: 'customers.view', shortcut: 'k', description: 'Danh bạ khách mua qua kênh riêng, lịch sử mua và công nợ.',
                children: [{ label: 'Danh sách khách hàng', path: '/customers' }, { label: 'Nhóm khách hàng', path: '/customers/groups' }] },
            { name: 'products', label: 'Sản phẩm', path: '/products', icon: Package, permission: 'products.view', anyPermission: ['inventory.view', 'purchasing.view'], shortcut: 'p', description: 'Sản phẩm, tồn kho, nhập hàng, kiểm hàng, chuyển hàng, nhà cung cấp và giá vốn.',
                alsoActive: ['inventory', 'purchasing'],
                children: [
                    { label: 'Danh sách sản phẩm', path: '/products', permission: 'products.view' },
                    { label: 'Quản lý kho', path: '/inventory', permission: 'inventory.view' },
                    { label: 'Đặt hàng nhập', path: '/purchasing/orders', permission: 'purchasing.view' },
                    { label: 'Nhập hàng', path: '/purchasing', permission: 'purchasing.view' },
                    { label: 'Kiểm hàng', path: '/inventory/stocktake', permission: 'inventory.view' },
                    { label: 'Chuyển hàng', path: '/inventory/transfers', permission: 'inventory.view' },
                    { label: 'Nhà cung cấp', path: '/purchasing/suppliers', permission: 'purchasing.view' },
                    { label: 'Điều chỉnh giá vốn', path: '/inventory/cost-adjustments', permission: 'inventory.view' },
                ] },
        ],
    },
    {
        label: 'Phân tích',
        items: [
            { name: 'cash', label: 'Thu chi', path: '/cash', icon: HandCoins, permission: 'finance.view', shortcut: 'q', description: 'Phiếu thu, phiếu chi, sổ quỹ và loại phiếu.',
                children: [{ label: 'Phiếu thu, phiếu chi', path: '/cash' }, { label: 'Sổ quỹ', path: '/reports/cashbook' }, { label: 'Quỹ', path: '/cash/accounts' }, { label: 'Loại phiếu', path: '/cash/types' }] },
            { name: 'finance', label: 'Tài chính', path: '/finance', icon: Wallet, permission: 'finance.view', description: 'Doanh thu, phí sàn, đối soát, lợi nhuận ước tính và đã xác nhận.' },
            { name: 'reports', label: 'Báo cáo', path: '/reports', icon: BarChart3, permission: 'reports.view', description: 'Bán hàng, lãi lỗ, kho, nhập hàng, công nợ và khách hàng.',
                children: [
                    { label: 'Báo cáo bán hàng', path: '/reports/group/sales' },
                    { label: 'Báo cáo nhập hàng', path: '/reports/group/purchases', permission: 'purchasing.view' },
                    { label: 'Báo cáo kho', path: '/reports/group/stock', permission: 'inventory.view' },
                    { label: 'Báo cáo tài chính', path: '/reports/group/finance', permission: 'finance.view' },
                    { label: 'Báo cáo khách hàng', path: '/reports/group/customers' },
                ] },
        ],
    },
    {
        label: 'Thiết lập',
        items: [
            { name: 'marketplaces', label: 'Kết nối sàn', path: '/marketplaces', icon: PlugZap, permission: 'marketplace.view', description: 'Kết nối Shopee, TikTok Shop, Lazada, Tiki bằng API chính thức.' },
            { name: 'settings', label: 'Cấu hình', path: '/settings', icon: Settings, permission: 'settings.manage', anyPermission: ['members.view', 'billing.view', 'audit.view'], shortcut: 's', alsoActive: ['members', 'subscription', 'audit', 'sales-channels'] },
            // Reached from Cấu hình like Sapo; kept here for their shortcuts and access checks.
            { name: 'sales-channels', label: 'Kênh bán riêng', path: '/sales-channels', icon: Store, permission: 'orders.view', hidden: true, description: 'Quầy, cửa hàng, Facebook, Zalo, website.' },
            { name: 'members', label: 'Nhân viên', path: '/members', icon: Users, permission: 'members.view', shortcut: 'm', hidden: true },
            { name: 'subscription', label: 'Gói dịch vụ', path: '/subscription', icon: CreditCard, permission: 'billing.view', hidden: true },
            { name: 'audit', label: 'Nhật ký', path: '/audit', icon: ScrollText, permission: 'audit.view', hidden: true },
        ],
    },
];

export const allNavItems = navGroups.flatMap((g) => g.items);
export const placeholderIcon = ReceiptText;
