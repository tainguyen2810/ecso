import { adjustmentReasons } from '../inventory/labels';
import { paymentMethods, returnStatus, returnTypes } from '../orders/labels';

export type ColumnType = 'text' | 'number' | 'money' | 'pct' | 'date' | 'datetime';
export type Tone = 'danger' | 'success' | 'muted';

export interface Column {
    key: string;
    label: string;
    type?: ColumnType;
    /** Value shown and exported; defaults to row[key]. */
    value?: (row: any) => string | number | null;
    /** Second line under the value, e.g. the SKU under a product name. */
    sub?: (row: any) => string | null;
    link?: (row: any) => string | null;
    tone?: (row: any) => Tone | undefined;
    /** Footer value taken from data.totals[key] (or this function). */
    total?: boolean | ((data: any) => string | number | null);
}

export interface Kpi {
    label: string;
    value: string | number;
    type?: ColumnType;
    hint?: string;
}

export interface DetailReport {
    key: string;
    title: string;
    description: string;
    endpoint: string;
    permission: string;
    /** range: from/to; date: a single "as of" date; none: current state. */
    period: 'range' | 'date' | 'none';
    search?: boolean;
    searchPlaceholder?: string;
    warehouse?: boolean;
    /** Filter by own sales channel. */
    channel?: boolean;
    tabs?: Record<string, string>;
    columns: (data: any, by: string) => Column[];
    rows?: (data: any) => any[];
    kpis?: (data: any) => Kpi[];
    note?: string;
    empty: string;
    /** A next step offered beside the export button, e.g. reorder what is running low. */
    action?: { label: string; to: string };
}

const variant = (r: any) => r.variant?.name ?? '—';
const variantSku = (r: any) => r.variant?.sku ?? null;
const variantLink = (r: any) => (r.variant?.id ? `/inventory/sku/${r.variant.id}` : null);
const isoDay = (iso: string) => {
    const [y, m, d] = iso.slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
};
const negative = (key: string) => (r: any) => (r[key] < 0 ? 'danger' : undefined);

export const reports: Record<string, DetailReport> = {
    'product-sales': {
        key: 'product-sales',
        title: 'Bán hàng theo sản phẩm',
        description: 'Số lượng, doanh thu, giá vốn và lãi gộp của từng SKU trên mọi kênh bán.',
        endpoint: '/reports/product-sales',
        permission: 'reports.view',
        period: 'range',
        search: true,
        empty: 'Chưa có hàng bán trong khoảng thời gian này.',
        note: 'Không tính đơn hủy và đơn hoàn. Chiết khấu là chiết khấu từng dòng hàng; chiết khấu cả đơn không chia về từng sản phẩm.',
        columns: () => [
            { key: 'variant', label: 'Sản phẩm', value: variant, sub: variantSku, link: variantLink },
            { key: 'orders', label: 'Đơn', type: 'number', total: true },
            { key: 'units', label: 'SL bán', type: 'number', total: true },
            { key: 'goods', label: 'Tiền hàng', type: 'money', total: true },
            { key: 'discount', label: 'Chiết khấu', type: 'money', total: true },
            { key: 'net', label: 'Doanh thu', type: 'money', total: true },
            { key: 'cogs', label: 'Giá vốn', type: 'money', total: true },
            { key: 'gross_profit', label: 'Lãi gộp', type: 'money', total: true, tone: negative('gross_profit') },
            { key: 'margin', label: 'Biên', type: 'pct' },
        ],
    },
    orders: {
        key: 'orders',
        title: 'Thống kê theo đơn hàng',
        description: 'Mỗi đơn một dòng: khách, kênh bán, tiền hàng, chiết khấu, phí giao, khách phải trả, giá vốn và lãi gộp.',
        endpoint: '/reports/orders',
        permission: 'reports.view',
        period: 'range',
        search: true,
        searchPlaceholder: 'Tìm mã đơn, tên khách',
        warehouse: true,
        channel: true,
        empty: 'Không có đơn nào trong kỳ.',
        note: 'Không tính đơn hủy và đơn hoàn. Lãi gộp = khách phải trả − phí giao − giá vốn; đơn chưa xuất kho chưa có giá vốn.',
        kpis: (d) => [
            { label: 'Số đơn', value: d.totals.orders, type: 'number' },
            { label: 'Khách phải trả', value: d.totals.total, type: 'money' },
            { label: 'Chiết khấu', value: d.totals.discount, type: 'money' },
            { label: 'Lãi gộp', value: d.totals.gross_profit, type: 'money' },
        ],
        columns: () => [
            { key: 'number', label: 'Mã đơn', link: (r) => `/orders/${r.id}`, sub: (r) => r.channel },
            { key: 'placed_at', label: 'Ngày bán', type: 'datetime' },
            { key: 'customer', label: 'Khách hàng' },
            { key: 'units', label: 'SL', type: 'number', total: true },
            { key: 'goods', label: 'Tiền hàng', type: 'money', total: true },
            { key: 'discount', label: 'Chiết khấu', type: 'money', total: true },
            { key: 'shipping_fee', label: 'Phí giao', type: 'money', total: true },
            { key: 'total', label: 'Khách phải trả', type: 'money', total: true },
            { key: 'cogs', label: 'Giá vốn', type: 'money', total: true },
            { key: 'gross_profit', label: 'Lãi gộp', type: 'money', total: true, tone: negative('gross_profit') },
        ],
    },
    'sales-lines': {
        key: 'sales-lines',
        title: 'Bán hàng chi tiết',
        description: 'Từng dòng hàng của từng đơn trong kỳ, kể cả dịch vụ.',
        endpoint: '/reports/sales-lines',
        permission: 'reports.view',
        period: 'range',
        search: true,
        searchPlaceholder: 'Tìm mã đơn, khách hàng, SKU, tên hàng',
        warehouse: true,
        channel: true,
        empty: 'Không có dòng hàng nào trong kỳ.',
        note: 'Không tính đơn hủy và đơn hoàn. Chiết khấu là chiết khấu từng dòng; chiết khấu cả đơn xem ở Thống kê theo đơn hàng.',
        kpis: (d) => [
            { label: 'Số đơn', value: d.totals.orders, type: 'number' },
            { label: 'Số lượng bán', value: d.totals.quantity, type: 'number' },
            { label: 'Thành tiền', value: d.totals.amount, type: 'money' },
            { label: 'Lãi gộp', value: d.totals.gross_profit, type: 'money' },
        ],
        columns: () => [
            { key: 'placed_at', label: 'Ngày bán', type: 'datetime' },
            { key: 'order', label: 'Mã đơn', value: (r) => r.order.number, link: (r) => `/orders/${r.order.id}`, sub: (r) => r.channel },
            { key: 'customer', label: 'Khách hàng' },
            { key: 'product', label: 'Sản phẩm', value: (r) => r.product.name, sub: (r) => (r.product.service ? 'Dịch vụ' : r.product.sku), link: (r) => (r.product.id ? `/inventory/sku/${r.product.id}` : null) },
            { key: 'quantity', label: 'SL', type: 'number', total: true },
            { key: 'unit_price', label: 'Đơn giá', type: 'money' },
            { key: 'discount', label: 'Chiết khấu', type: 'money', total: true },
            { key: 'amount', label: 'Thành tiền', type: 'money', total: true },
            { key: 'cogs', label: 'Giá vốn', type: 'money', total: true },
            { key: 'gross_profit', label: 'Lãi gộp', type: 'money', total: true, tone: negative('gross_profit') },
        ],
    },
    'stock-detail': {
        key: 'stock-detail',
        title: 'Tồn kho chi tiết',
        description: 'Tồn thực tế, đang giữ cho đơn, có thể bán và đang về từ đơn nhập, theo từng kho, tại thời điểm xem.',
        endpoint: '/reports/stock-detail',
        permission: 'inventory.view',
        period: 'none',
        search: true,
        warehouse: true,
        empty: 'Chưa có tồn kho.',
        note: 'Có thể bán = tồn thực tế − đang giữ − tồn an toàn. Đang về = phần còn chờ của đơn nhập đã đặt.',
        kpis: (d) => [
            { label: 'SKU có tồn', value: d.totals.skus, type: 'number' },
            { label: 'Tồn thực tế', value: d.totals.on_hand, type: 'number' },
            { label: 'Đang giữ cho đơn', value: d.totals.reserved, type: 'number' },
            { label: 'Giá trị tồn', value: d.totals.value, type: 'money' },
        ],
        columns: () => [
            { key: 'variant', label: 'Sản phẩm', value: variant, sub: variantSku, link: variantLink },
            { key: 'warehouse', label: 'Kho' },
            { key: 'on_hand', label: 'Tồn thực tế', type: 'number', total: true },
            { key: 'reserved', label: 'Đang giữ', type: 'number', total: true },
            { key: 'available', label: 'Khả dụng', type: 'number', total: true, tone: negative('available') },
            { key: 'sellable', label: 'Có thể bán', type: 'number', total: true },
            { key: 'incoming', label: 'Đang về', type: 'number', total: true },
            { key: 'cost_price', label: 'Giá vốn', type: 'money' },
            { key: 'value', label: 'Giá trị tồn', type: 'money', total: true },
        ],
    },
    returns: {
        key: 'returns',
        title: 'Trả hàng',
        description: 'Yêu cầu trả hàng và hoàn tiền tạo trong kỳ (không tính yêu cầu bị từ chối).',
        endpoint: '/reports/returns',
        permission: 'reports.view',
        period: 'range',
        tabs: { order: 'Theo đơn trả', product: 'Theo sản phẩm' },
        empty: 'Không có đơn trả hàng nào trong kỳ.',
        columns: (_d, by) =>
            by === 'product'
                ? [
                      { key: 'variant', label: 'Sản phẩm', value: variant, sub: variantSku, link: variantLink },
                      { key: 'returns', label: 'Số lần trả', type: 'number', total: true },
                      { key: 'quantity', label: 'SL trả', type: 'number', total: true },
                      { key: 'received', label: 'Đã nhận', type: 'number', total: true },
                      { key: 'sellable', label: 'Bán lại được', type: 'number', total: true },
                      { key: 'damaged', label: 'Hỏng', type: 'number', total: true, tone: (r) => (r.damaged ? 'danger' : undefined) },
                      { key: 'value', label: 'Giá trị hàng trả', type: 'money', total: true },
                  ]
                : [
                      { key: 'number', label: 'Mã trả hàng', link: (r) => `/returns/${r.id}`, sub: (r) => (r.order?.number ? `Đơn ${r.order.number}` : null) },
                      { key: 'created_at', label: 'Ngày tạo', type: 'datetime' },
                      { key: 'customer', label: 'Khách hàng', value: (r) => r.customer ?? '—' },
                      { key: 'type', label: 'Loại', value: (r) => returnTypes[r.type] ?? r.type },
                      { key: 'status', label: 'Trạng thái', value: (r) => returnStatus[r.status]?.label ?? r.status },
                      { key: 'quantity', label: 'SL', type: 'number', total: true },
                      { key: 'refund_amount', label: 'Hoàn tiền', type: 'money', total: true },
                  ],
    },
    payments: {
        key: 'payments',
        title: 'Thanh toán của khách',
        description: 'Tiền khách trả và tiền hoàn cho khách trên đơn của kênh bán riêng.',
        endpoint: '/reports/payments',
        permission: 'finance.view',
        period: 'range',
        tabs: { method: 'Theo phương thức', staff: 'Theo nhân viên', day: 'Theo ngày', warehouse: 'Theo kho' },
        empty: 'Không có khoản thanh toán nào trong kỳ.',
        note: 'Đơn trên sàn do sàn thu tiền nên không có ở đây. Khoản đã hủy không được tính.',
        columns: (_d, by) => [
            {
                key: 'label',
                label: { method: 'Phương thức', staff: 'Nhân viên', day: 'Ngày', warehouse: 'Kho' }[by] ?? '',
                value: (r) => (by === 'method' ? (paymentMethods[r.label] ?? r.label) : by === 'day' ? isoDay(r.label) : r.label),
            },
            { key: 'entries', label: 'Số phiếu', type: 'number', total: true },
            { key: 'received', label: 'Đã thu', type: 'money', total: true },
            { key: 'refunded', label: 'Đã hoàn', type: 'money', total: true },
            { key: 'net', label: 'Thực thu', type: 'money', total: true, tone: negative('net') },
        ],
    },
    purchases: {
        key: 'purchases',
        title: 'Nhập hàng',
        description: 'Hàng đã nhập kho trong kỳ (không tính tồn đầu kỳ khai báo) và hàng đã trả nhà cung cấp.',
        endpoint: '/reports/purchases',
        permission: 'purchasing.view',
        period: 'range',
        tabs: { supplier: 'Theo nhà cung cấp', product: 'Theo sản phẩm', day: 'Theo ngày' },
        empty: 'Chưa nhập hàng trong kỳ.',
        columns: (_d, by) => [
            {
                key: 'label',
                label: { supplier: 'Nhà cung cấp', product: 'Sản phẩm', day: 'Ngày' }[by] ?? '',
                value: (r) => (by === 'day' ? isoDay(r.label) : r.label),
                sub: (r) => r.sku,
                link: (r) => (by === 'supplier' && r.id ? `/purchasing/suppliers/${r.id}` : by === 'product' && r.id ? `/inventory/sku/${r.id}` : null),
            },
            { key: 'receipts', label: 'Phiếu nhập', type: 'number', total: true },
            { key: 'quantity', label: 'SL nhập', type: 'number', total: true },
            { key: 'value', label: 'Giá trị nhập', type: 'money', total: true },
            { key: 'returned', label: 'Hàng trả NCC', type: 'money', total: true },
            { key: 'net', label: 'Nhập thuần', type: 'money', total: true },
        ],
    },
    customers: {
        key: 'customers',
        title: 'Khách hàng mua hàng',
        description: 'Ai mua nhiều nhất, khách mới hay khách quay lại.',
        endpoint: '/reports/customers',
        permission: 'reports.view',
        period: 'range',
        empty: 'Chưa có khách hàng có hồ sơ mua trong kỳ.',
        note: 'Khách mới là khách có đơn đầu tiên trong kỳ. Đơn khách lẻ và đơn sàn không có hồ sơ khách nên chỉ được đếm số đơn.',
        kpis: (d) => [
            { label: 'Khách mua hàng', value: d.summary.customers, type: 'number' },
            { label: 'Khách mới', value: d.summary.new, type: 'number' },
            { label: 'Khách quay lại', value: d.summary.returning, type: 'number' },
            { label: 'Đơn khách lẻ / sàn', value: d.summary.anonymous_orders, type: 'number' },
        ],
        columns: () => [
            { key: 'customer', label: 'Khách hàng', value: (r) => r.customer.name, sub: (r) => [r.customer.code, r.customer.group].filter(Boolean).join(' · '), link: (r) => `/customers/${r.customer.id}` },
            { key: 'is_new', label: 'Loại', value: (r) => (r.is_new ? 'Khách mới' : 'Quay lại'), tone: (r) => (r.is_new ? 'success' : undefined) },
            { key: 'orders', label: 'Đơn trong kỳ', type: 'number' },
            { key: 'revenue', label: 'Doanh thu', type: 'money', total: (d) => d.summary.revenue },
            { key: 'average', label: 'Giá trị TB/đơn', type: 'money' },
            { key: 'lifetime_orders', label: 'Tổng đơn từ trước', type: 'number' },
            { key: 'last_order_at', label: 'Mua gần nhất', type: 'date' },
        ],
    },
    'stock-on-hand': {
        key: 'stock-on-hand',
        title: 'Tồn kho tại ngày',
        description: 'Số lượng và giá trị tồn của từng SKU ở từng kho vào cuối một ngày bất kỳ.',
        endpoint: '/reports/stock-on-hand',
        permission: 'inventory.view',
        period: 'date',
        search: true,
        warehouse: true,
        empty: 'Không có hàng tồn vào ngày này.',
        note: 'Số lượng dựng lại từ sổ biến động kho nên xem được ngày trong quá khứ. Giá trị tính theo giá vốn bình quân hiện tại.',
        kpis: (d) => [
            { label: 'SKU còn hàng', value: d.totals.skus, type: 'number' },
            { label: 'Tổng số lượng', value: d.totals.quantity, type: 'number' },
            { label: 'Giá trị tồn', value: d.totals.value, type: 'money' },
            ...d.warehouses.slice(0, 1).map((w: any) => ({ label: w.name, value: w.value, type: 'money' as const, hint: `${w.quantity.toLocaleString('vi-VN')} sp` })),
        ],
        columns: (d) => [
            { key: 'variant', label: 'Sản phẩm', value: variant, sub: variantSku, link: variantLink },
            ...(d.rows.some((r: any) => r.unit) ? [{ key: 'unit', label: 'ĐVT', value: (r: any) => r.unit ?? '' }] : []),
            ...(d.warehouses.length > 1
                ? d.warehouses.map((w: any) => ({ key: `wh-${w.id}`, label: w.name, type: 'number' as const, value: (r: any) => r.by_warehouse[w.id] ?? 0, total: () => w.quantity }))
                : []),
            { key: 'quantity', label: 'Tồn', type: 'number', total: true },
            { key: 'unit_cost', label: 'Giá vốn', type: 'money' },
            { key: 'value', label: 'Giá trị tồn', type: 'money', total: true },
            { key: 'share', label: 'Tỷ trọng', type: 'pct' },
        ],
    },
    'low-stock': {
        key: 'low-stock',
        title: 'Hàng dưới định mức',
        description: 'SKU có tồn khả dụng bằng hoặc thấp hơn mức tồn an toàn đã đặt.',
        endpoint: '/reports/low-stock',
        permission: 'inventory.view',
        period: 'none',
        warehouse: true,
        empty: 'Không có SKU nào dưới định mức. Đặt mức tồn an toàn ở trang Tồn kho để được cảnh báo.',
        action: { label: 'Lập đơn nhập từ gợi ý', to: '/purchasing/reorder' },
        kpis: (d) => [
            { label: 'SKU dưới định mức', value: d.totals.skus, type: 'number' },
            { label: 'Đã hết hàng', value: d.totals.out_of_stock, type: 'number' },
        ],
        columns: () => [
            { key: 'variant', label: 'Sản phẩm', value: variant, sub: variantSku, link: variantLink },
            { key: 'warehouse', label: 'Kho', value: (r) => r.warehouse.name },
            { key: 'on_hand', label: 'Tồn thực', type: 'number' },
            { key: 'reserved', label: 'Đang giữ', type: 'number' },
            { key: 'available', label: 'Khả dụng', type: 'number', tone: (r) => (r.out_of_stock ? 'danger' : undefined) },
            { key: 'safety_stock', label: 'Định mức', type: 'number' },
            { key: 'shortfall', label: 'Thiếu', type: 'number', tone: () => 'danger' },
            { key: 'sold_30d', label: 'Bán 30 ngày', type: 'number' },
            { key: 'days_left', label: 'Đủ bán', value: (r) => (r.days_left === null ? '—' : `${r.days_left} ngày`) },
        ],
    },
    'supplier-payments': {
        key: 'supplier-payments',
        title: 'Thanh toán nhập hàng',
        description: 'Tiền đã trả nhà cung cấp trong kỳ (không tính phiếu chi đã hủy).',
        endpoint: '/reports/supplier-payments',
        permission: 'purchasing.view',
        period: 'range',
        tabs: { method: 'Theo phương thức', staff: 'Theo nhân viên', supplier: 'Theo nhà cung cấp', day: 'Theo ngày' },
        empty: 'Chưa trả tiền nhà cung cấp nào trong kỳ.',
        columns: (_d, by) => [
            {
                key: 'label',
                label: { method: 'Phương thức', staff: 'Nhân viên', supplier: 'Nhà cung cấp', day: 'Ngày' }[by] ?? '',
                value: (r) => (by === 'method' ? (paymentMethods[r.label] ?? r.label) : by === 'day' ? isoDay(r.label) : r.label),
                link: (r) => (by === 'supplier' && r.id ? `/purchasing/suppliers/${r.id}` : null),
            },
            { key: 'entries', label: 'Số phiếu chi', type: 'number', total: true },
            ...(by === 'supplier' ? [] : [{ key: 'suppliers', label: 'Số NCC', type: 'number' as const }]),
            { key: 'paid', label: 'Đã trả', type: 'money', total: true },
        ],
    },
    adjustments: {
        key: 'adjustments',
        title: 'Kiểm kê và điều chỉnh kho',
        description: 'Phiếu kiểm kê, xuất hủy, điều chỉnh đã duyệt trong kỳ: số lượng tăng, giảm và giá trị chênh lệch.',
        endpoint: '/reports/adjustments',
        permission: 'inventory.view',
        period: 'range',
        warehouse: true,
        tabs: { document: 'Theo phiếu', product: 'Theo sản phẩm', reason: 'Theo lý do' },
        empty: 'Không có phiếu điều chỉnh nào được duyệt trong kỳ.',
        note: 'Giá trị tính theo giá vốn bình quân hiện tại; số âm là hàng giảm (thiếu, hỏng, mất).',
        columns: (_d, by) => [
            {
                key: 'label',
                label: { document: 'Phiếu', product: 'Sản phẩm', reason: 'Lý do' }[by] ?? '',
                value: (r) => (by === 'reason' ? (adjustmentReasons[r.label] ?? r.label) : r.label),
                sub: (r) => (by === 'document' ? (adjustmentReasons[r.sku] ?? r.sku) : by === 'product' ? r.sku : null),
                link: (r) => (by === 'document' ? `/inventory/adjustments/${r.id}` : by === 'product' ? `/inventory/sku/${r.id}` : null),
            },
            ...(by === 'document'
                ? [
                      { key: 'decided_at', label: 'Ngày duyệt', type: 'datetime' as const },
                      { key: 'warehouse', label: 'Kho' },
                  ]
                : [{ key: 'documents', label: 'Số phiếu', type: 'number' as const, total: true }]),
            { key: 'increase', label: 'SL tăng', type: 'number', total: true },
            { key: 'decrease', label: 'SL giảm', type: 'number', total: true, tone: (r) => (r.decrease ? 'danger' : undefined) },
            { key: 'value', label: 'Giá trị chênh lệch', type: 'money', total: true, tone: negative('value') },
        ],
    },
    'over-stock': {
        key: 'over-stock',
        title: 'Hàng vượt tồn tối đa',
        description: 'SKU đang tồn nhiều hơn mức tối đa đã đặt cho kho, và số tiền đang nằm ở phần dư.',
        endpoint: '/reports/over-stock',
        permission: 'inventory.view',
        period: 'none',
        warehouse: true,
        empty: 'Không có SKU nào vượt tồn tối đa. Đặt tồn tối đa ở trang chi tiết sản phẩm, theo từng kho.',
        kpis: (d) => [
            { label: 'SKU vượt mức', value: d.totals.skus, type: 'number' },
            { label: 'Số lượng dư', value: d.totals.excess, type: 'number' },
            { label: 'Giá trị hàng dư', value: d.totals.excess_value, type: 'money' },
        ],
        columns: () => [
            { key: 'variant', label: 'Sản phẩm', value: variant, sub: variantSku, link: variantLink },
            { key: 'warehouse', label: 'Kho', value: (r) => r.warehouse.name },
            { key: 'on_hand', label: 'Tồn thực', type: 'number' },
            { key: 'max_stock', label: 'Tồn tối đa', type: 'number' },
            { key: 'excess', label: 'Dư', type: 'number', total: true, tone: () => 'danger' },
            { key: 'excess_value', label: 'Giá trị dư', type: 'money', total: true },
            { key: 'sold_30d', label: 'Bán 30 ngày', type: 'number' },
            { key: 'days_left', label: 'Đủ bán', value: (r) => (r.days_left === null ? 'Không bán' : `${r.days_left} ngày`) },
        ],
    },
    receivables: {
        key: 'receivables',
        title: 'Công nợ khách hàng',
        description: 'Đơn bán ở kênh riêng chưa thu đủ tiền, gom theo khách hàng.',
        endpoint: '/reports/receivables',
        permission: 'finance.view',
        period: 'none',
        empty: 'Không có khách nào còn nợ.',
        kpis: (d) => [
            { label: 'Khách còn nợ', value: d.totals.customers, type: 'number' },
            { label: 'Đơn chưa thu đủ', value: d.totals.orders, type: 'number' },
            { label: 'Tổng phải thu', value: d.totals.due, type: 'money' },
        ],
        columns: () => [
            { key: 'customer', label: 'Khách hàng', value: (r) => r.customer?.name ?? 'Khách lẻ', sub: (r) => r.customer?.code ?? null, link: (r) => (r.customer ? `/customers/${r.customer.id}` : null) },
            { key: 'orders', label: 'Đơn nợ', type: 'number', total: true },
            { key: 'total', label: 'Giá trị đơn', type: 'money' },
            { key: 'paid', label: 'Đã trả', type: 'money' },
            { key: 'due', label: 'Còn nợ', type: 'money', total: true, tone: () => 'danger' },
            { key: 'age_days', label: 'Nợ lâu nhất', value: (r) => (r.age_days === null ? '—' : `${r.age_days} ngày`), tone: (r) => (r.age_days > 30 ? 'danger' : undefined) },
        ],
    },
    payables: {
        key: 'payables',
        title: 'Công nợ nhà cung cấp',
        description: 'Tiền hàng đã nhận chưa trả nhà cung cấp (đã trừ hàng trả lại).',
        endpoint: '/reports/payables',
        permission: 'purchasing.view',
        period: 'none',
        empty: 'Không còn nợ nhà cung cấp nào.',
        kpis: (d) => [
            { label: 'Nhà cung cấp còn nợ', value: d.totals.suppliers, type: 'number' },
            { label: 'Tổng phải trả', value: d.totals.debt, type: 'money' },
        ],
        columns: () => [
            { key: 'supplier', label: 'Nhà cung cấp', value: (r) => r.supplier.name, sub: (r) => r.supplier.code, link: (r) => `/purchasing/suppliers/${r.supplier.id}` },
            { key: 'orders', label: 'Đơn mua', type: 'number' },
            { key: 'purchased', label: 'Tiền hàng đã nhận', type: 'money' },
            { key: 'paid', label: 'Đã trả', type: 'money' },
            { key: 'debt', label: 'Còn nợ', type: 'money', total: true, tone: (r) => (r.debt > 0 ? 'danger' : 'success') },
        ],
    },
};
