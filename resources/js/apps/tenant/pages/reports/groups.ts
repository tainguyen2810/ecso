import type { Component } from 'vue';
import { ArrowLeftRight, BookOpen, Boxes, ClipboardCheck, Coins, FileText, Flame, Landmark, Layers, List, Package, PackagePlus, Receipt, Store, TrendingDown, TrendingUp, Undo2, User, UserRound, Users, Wallet, Warehouse } from 'lucide-vue-next';

/** The five report menus of Sapo; each lists the reports behind it. */
export type ReportGroupKey = 'sales' | 'purchases' | 'stock' | 'finance' | 'customers';

export interface ReportLink {
    title: string;
    description: string;
    icon: Component;
    permission: string;
    /** Route: a named page, or a detail report from catalog.ts (`report` + optional tab). */
    to: { name: string; query?: Record<string, string> } | { report: string; by?: string };
    /** Does the report take the period chosen on the group page? */
    ranged?: boolean;
    /** Tabs of the same report shown as chips under the description. */
    chips?: { label: string; by: string }[];
}

export const reportGroups: Record<ReportGroupKey, { title: string; permission: string; links: ReportLink[] }> = {
    sales: {
        title: 'Báo cáo bán hàng',
        permission: 'reports.view',
        links: [
            { title: 'Thống kê theo đơn hàng', description: 'Mỗi đơn một dòng: khách, kênh, tiền hàng, chiết khấu, phí giao, giá vốn, lãi gộp.', icon: FileText, permission: 'reports.view', to: { report: 'orders' }, ranged: true },
            { title: 'Thống kê theo sản phẩm', description: 'Số lượng, doanh thu, giá vốn và lãi gộp từng SKU.', icon: Package, permission: 'reports.view', to: { report: 'product-sales' }, ranged: true },
            { title: 'Bán hàng chi tiết', description: 'Từng dòng hàng của từng đơn: ngày, mã đơn, khách, sản phẩm, giá, chiết khấu, lãi gộp.', icon: List, permission: 'reports.view', to: { report: 'sales-lines' }, ranged: true },
            { title: 'Bán chạy, bán chậm', description: 'Top sản phẩm bán chạy và hàng tồn lâu không bán được.', icon: Flame, permission: 'reports.view', to: { name: 'report-products' }, ranged: true },
            { title: 'Doanh thu theo kênh bán', description: 'Doanh thu, lợi nhuận theo từng kênh và gian hàng.', icon: Store, permission: 'reports.view', to: { name: 'report-sales', query: { by: 'channel' } }, ranged: true },
            { title: 'Doanh thu theo nhân viên', description: 'Ai bán được bao nhiêu.', icon: User, permission: 'reports.view', to: { name: 'report-sales', query: { by: 'staff' } }, ranged: true },
            { title: 'Doanh thu theo kho xuất', description: 'Doanh thu, lợi nhuận theo kho, chi nhánh.', icon: Warehouse, permission: 'reports.view', to: { name: 'report-sales', query: { by: 'warehouse' } }, ranged: true },
            {
                title: 'Thanh toán',
                description: 'Tiền khách trả và tiền hoàn trên đơn của kênh bán riêng.',
                icon: Wallet,
                permission: 'finance.view',
                to: { report: 'payments', by: 'day' },
                ranged: true,
                chips: [
                    { label: 'Theo thời gian', by: 'day' },
                    { label: 'Theo nhân viên', by: 'staff' },
                    { label: 'Theo phương thức', by: 'method' },
                    { label: 'Theo kho', by: 'warehouse' },
                ],
            },
            {
                title: 'Trả hàng',
                description: 'Đơn trả hàng và hoàn tiền trong kỳ.',
                icon: Undo2,
                permission: 'reports.view',
                to: { report: 'returns', by: 'order' },
                ranged: true,
                chips: [
                    { label: 'Theo đơn hàng', by: 'order' },
                    { label: 'Theo sản phẩm', by: 'product' },
                ],
            },
        ],
    },
    purchases: {
        title: 'Báo cáo nhập hàng',
        permission: 'purchasing.view',
        links: [
            {
                title: 'Báo cáo hàng nhập kho',
                description: 'Hàng đã nhập kho và hàng trả nhà cung cấp trong kỳ.',
                icon: PackagePlus,
                permission: 'purchasing.view',
                to: { report: 'purchases', by: 'day' },
                ranged: true,
                chips: [
                    { label: 'Theo thời gian', by: 'day' },
                    { label: 'Theo nhà cung cấp', by: 'supplier' },
                    { label: 'Theo sản phẩm', by: 'product' },
                ],
            },
            {
                title: 'Báo cáo thanh toán nhập hàng',
                description: 'Các khoản đã trả nhà cung cấp.',
                icon: Coins,
                permission: 'purchasing.view',
                to: { report: 'supplier-payments', by: 'day' },
                ranged: true,
                chips: [
                    { label: 'Theo thời gian', by: 'day' },
                    { label: 'Theo nhân viên', by: 'staff' },
                    { label: 'Theo phương thức', by: 'method' },
                    { label: 'Theo nhà cung cấp', by: 'supplier' },
                ],
            },
            { title: 'Công nợ nhà cung cấp', description: 'Còn phải trả từng nhà cung cấp.', icon: Landmark, permission: 'purchasing.view', to: { report: 'payables' } },
        ],
    },
    stock: {
        title: 'Báo cáo kho',
        permission: 'inventory.view',
        links: [
            { title: 'Báo cáo tồn kho', description: 'Số lượng và giá trị tồn của từng kho và toàn hệ thống tại một ngày bất kỳ.', icon: Boxes, permission: 'inventory.view', to: { report: 'stock-on-hand' } },
            { title: 'Báo cáo tồn kho chi tiết', description: 'Tồn thực tế, đang giữ cho đơn, có thể bán, đang về từ đơn nhập, theo từng kho.', icon: Layers, permission: 'inventory.view', to: { report: 'stock-detail' } },
            { title: 'Sổ kho', description: 'Lịch sử nhập, xuất, chuyển, kiểm của từng SKU (chọn SKU ở Quản lý kho).', icon: BookOpen, permission: 'inventory.view', to: { name: 'inventory' } },
            { title: 'Tồn kho dưới định mức', description: 'SKU đã chạm tồn an toàn, kèm gợi ý nhập hàng.', icon: TrendingDown, permission: 'inventory.view', to: { report: 'low-stock' } },
            { title: 'Tồn kho vượt định mức', description: 'SKU vượt tồn tối đa, vốn đang bị chôn.', icon: TrendingUp, permission: 'inventory.view', to: { report: 'over-stock' } },
            { title: 'Xuất nhập tồn', description: 'Tồn đầu kỳ, nhập, xuất trong kỳ và tồn cuối kỳ của từng sản phẩm.', icon: ArrowLeftRight, permission: 'inventory.view', to: { name: 'report-stock' }, ranged: true },
            {
                title: 'Báo cáo kiểm kê hàng hóa',
                description: 'Kết quả phiếu kiểm, điều chỉnh, xuất hủy: số lệch, giá trị lệch và lý do.',
                icon: ClipboardCheck,
                permission: 'inventory.view',
                to: { report: 'adjustments', by: 'document' },
                ranged: true,
                chips: [
                    { label: 'Theo phiếu', by: 'document' },
                    { label: 'Theo sản phẩm', by: 'product' },
                    { label: 'Theo lý do', by: 'reason' },
                ],
            },
        ],
    },
    finance: {
        title: 'Báo cáo tài chính',
        permission: 'finance.view',
        links: [
            { title: 'Báo cáo lãi lỗ', description: 'Doanh thu, chi phí và lợi nhuận của cửa hàng.', icon: TrendingUp, permission: 'finance.view', to: { name: 'report-profit-loss' }, ranged: true },
            { title: 'Sổ quỹ', description: 'Các khoản thu chi theo ngày, tồn quỹ đầu và cuối kỳ.', icon: Receipt, permission: 'finance.view', to: { name: 'report-cashbook' }, ranged: true },
            { title: 'Công nợ khách hàng', description: 'Khách còn nợ bao nhiêu, nợ từ bao giờ.', icon: Users, permission: 'finance.view', to: { report: 'receivables' } },
            { title: 'Công nợ nhà cung cấp', description: 'Còn phải trả nhà cung cấp.', icon: Landmark, permission: 'purchasing.view', to: { report: 'payables' } },
        ],
    },
    customers: {
        title: 'Báo cáo khách hàng',
        permission: 'reports.view',
        links: [
            { title: 'Khách mua hàng', description: 'Ai mua nhiều nhất, khách mới hay khách quay lại.', icon: UserRound, permission: 'reports.view', to: { report: 'customers' }, ranged: true },
            { title: 'Doanh thu, lợi nhuận theo khách', description: 'Mỗi khách mang lại bao nhiêu doanh thu và lãi gộp.', icon: Coins, permission: 'reports.view', to: { name: 'report-sales', query: { by: 'customer' } }, ranged: true },
            { title: 'Công nợ khách hàng', description: 'Khách còn nợ bao nhiêu.', icon: Landmark, permission: 'finance.view', to: { report: 'receivables' } },
        ],
    },
};

/** Which group a page or detail report belongs to (for its back link). */
export function groupOf(routeName: string, report?: string): ReportGroupKey {
    if (report) {
        const hit = (Object.keys(reportGroups) as ReportGroupKey[]).find((g) => reportGroups[g].links.some((l) => 'report' in l.to && l.to.report === report));
        if (hit) return hit;
    }
    return ({ 'report-profit-loss': 'finance', 'report-cashbook': 'finance', 'report-stock': 'stock' } as Record<string, ReportGroupKey>)[routeName] ?? 'sales';
}
