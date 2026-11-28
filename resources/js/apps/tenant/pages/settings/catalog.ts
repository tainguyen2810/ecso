import type { Component } from 'vue';
import { ArrowRightLeft, Boxes, Cable, CreditCard, HandCoins, Hash, MessageSquareWarning, Percent, Printer, Receipt, RefreshCw, ScrollText, SlidersHorizontal, Sparkles, Store, Users, UsersRound, Wallet, Warehouse } from 'lucide-vue-next';

/**
 * Every tile of Cấu hình, grouped. `own` = a page of this settings area: it also shows in the left menu of the settings
 * pages. The others open their own module page (Nhân viên, Kho, Nhóm khách…).
 */
export interface SettingsTile {
    to: string;
    icon: Component;
    title: string;
    description: string;
    permission: string;
    anyPermission?: string[];
    own?: boolean;
    /** Other paths that belong to this page (sub pages), for the active item of the left menu. */
    also?: string[];
}
export type SettingsTone = 'blue' | 'green' | 'violet';
export interface SettingsGroup {
    title: string;
    tone: SettingsTone;
    tiles: SettingsTile[];
}

export const settingsGroups: SettingsGroup[] = [
    {
        title: 'Cửa hàng',
        tone: 'blue',
        tiles: [
            { to: '/settings/store', icon: Store, title: 'Thông tin cửa hàng', description: 'Tên, số điện thoại, địa chỉ, mã số thuế, logo in trên hóa đơn', permission: 'settings.manage', own: true },
            { to: '/inventory/warehouses', icon: Warehouse, title: 'Quản lý kho, chi nhánh', description: 'Thêm mới và quản lý kho, kho mặc định', permission: 'inventory.view' },
            { to: '/members', icon: Users, title: 'Nhân viên và phân quyền', description: 'Tài khoản nhân viên, vai trò, lời mời', permission: 'members.view' },
            { to: '/subscription', icon: CreditCard, title: 'Gói dịch vụ', description: 'Gói đang dùng, giới hạn và gia hạn', permission: 'billing.view' },
            { to: '/settings/pricing', icon: Percent, title: 'Thuế và chính sách giá', description: 'Thuế bán, thuế nhập mặc định; giá bán mặc định; cho nhân viên sửa giá', permission: 'settings.manage', own: true, also: ['/settings/pricing/fees'] },
            { to: '/settings/payments', icon: Wallet, title: 'Thanh toán', description: 'Tài khoản ngân hàng (VietQR trên hóa đơn), phương thức mặc định', permission: 'settings.manage', own: true },
            { to: '/settings/print', icon: Printer, title: 'Mẫu in', description: 'Khổ in mặc định, logo, mã VietQR, chữ ký, lời chào cuối phiếu', permission: 'settings.manage', own: true },
            { to: '/products/taxonomy', icon: Boxes, title: 'Danh mục và thương hiệu', description: 'Phân nhóm sản phẩm để lọc và báo cáo', permission: 'products.view' },
        ],
    },
    {
        title: 'Bán hàng',
        tone: 'green',
        tiles: [
            { to: '/settings/order-codes', icon: Hash, title: 'Mã đơn hàng', description: 'Kí hiệu và số chạy liên tục của mã đơn (VD: SON50892), cấp mã cho đơn đã có', permission: 'settings.manage', own: true },
            { to: '/settings/sales', icon: SlidersHorizontal, title: 'Cấu hình bán hàng', description: 'Sửa thời gian bán, số liên in, bán tại quầy, lý do hủy và trả hàng', permission: 'settings.manage', own: true },
            { to: '/sales-channels', icon: Store, title: 'Kênh bán riêng', description: 'Quầy, cửa hàng, Facebook, Zalo, website', permission: 'orders.view' },
            { to: '/settings/subscriptions', icon: RefreshCw, title: 'Thuê bao', description: 'Ngày nhắc khách gia hạn, gửi qua Zalo, báo nhân viên', permission: 'settings.manage', own: true },
            { to: '/settings/connections', icon: Cable, title: 'Kết nối dịch vụ', description: 'Vận chuyển, hóa đơn điện tử, Zalo của shop', permission: 'settings.manage', own: true, also: ['/settings/connections/zalo'] },
            { to: '/settings/sales#reasons', icon: MessageSquareWarning, title: 'Lý do hủy, trả hàng', description: 'Danh sách lý do chọn khi hủy đơn hoặc nhận hàng trả', permission: 'settings.manage' },
            { to: '/cash/types', icon: Receipt, title: 'Loại phiếu thu chi', description: 'Phân loại phiếu thu, phiếu chi', permission: 'finance.view' },
            { to: '/customers/groups', icon: UsersRound, title: 'Nhóm khách hàng', description: 'Nhóm khách và chính sách giá theo nhóm', permission: 'customers.view' },
        ],
    },
    {
        title: 'Dữ liệu',
        tone: 'violet',
        tiles: [
            { to: '/settings/data-migration', icon: ArrowRightLeft, title: 'Chuyển dữ liệu', description: 'Chuyển sản phẩm, tồn kho, khách hàng, công nợ từ Sapo, KiotViet', permission: 'products.manage', anyPermission: ['products.manage', 'customers.import'], own: true },
            { to: '/settings/demo-store', icon: Sparkles, title: 'Cửa hàng mẫu', description: 'Cửa hàng riêng có dữ liệu giả để bấm thử, không đụng dữ liệu thật', permission: 'settings.manage', own: true },
            { to: '/audit', icon: ScrollText, title: 'Nhật ký hoạt động', description: 'Ai làm gì, lúc nào trong doanh nghiệp', permission: 'audit.view' },
            { to: '/reports/cashbook', icon: HandCoins, title: 'Sổ quỹ', description: 'Dòng tiền thu chi theo ngày', permission: 'finance.view' },
        ],
    },
];

export const toneClasses: Record<SettingsTone, string> = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-emerald-50 text-emerald-600',
    violet: 'bg-violet-50 text-violet-600',
};

/** Whether the person can open a tile. */
export const canOpen = (t: SettingsTile, can: (p: string) => boolean) => (t.anyPermission ? t.anyPermission.some(can) : can(t.permission));

/** The settings page a path belongs to (for the left menu and the breadcrumb). */
export function ownTileFor(path: string): { group: SettingsGroup; tile: SettingsTile } | null {
    for (const group of settingsGroups) {
        for (const tile of group.tiles) {
            if (tile.own && (tile.to === path || tile.also?.includes(path))) return { group, tile };
        }
    }
    return null;
}
