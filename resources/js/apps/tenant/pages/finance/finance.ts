/** Shapes of /finance/summary and /finance/details, and the formulas shown next to each figure. */

export interface ShopRow {
    id: string;
    kind: 'account' | 'channel';
    shop: string;
    marketplace: string;
    ordered_orders: number;
    cancelled_orders: number;
    revenue: number;
    orders: number;
    returns: number;
    net_revenue: number;
    cogs: number | null;
    gross_profit: number | null;
    fees: number;
    fees_unknown_orders: number;
    shipping: number;
    profit: number | null;
    margin: number | null;
    average_order: number | null;
    growth: number | null;
}

export interface DayRow {
    day: string;
    orders: number;
    revenue: number;
    completed_orders: number;
    net_revenue: number;
    cogs: number | null;
    gross_profit: number | null;
}

export interface FinanceData {
    range: { from: string; to: string };
    previous_range: { from: string; to: string };
    scoped: boolean;
    can_view_profit: boolean;
    ordered: { orders: number; revenue: number; goods: number };
    completed: { orders: number; revenue: number; goods: number; shipping: number };
    awaiting_completion: { orders: number; revenue: number; goods: number };
    cancelled: { orders: number; revenue: number };
    refunds: number;
    net_revenue: number;
    cogs: number | null;
    cogs_returned: number | null;
    cogs_missing_lines: number | null;
    estimated_gross_profit: number | null;
    fees: { total: number; confirmed: number; confirmed_orders: number; estimated: number; estimated_orders: number; unknown_orders: number };
    payout: { received: number | null; received_orders: number; pending_orders: number; pending_expected: number | null };
    selling_expenses: number | null;
    contribution_profit: number | null;
    stock_loss: number | null;
    operating_expenses: number | null;
    other_income: number | null;
    other_expenses: number | null;
    estimated_profit: number | null;
    previous: { orders: number; net_revenue: number; estimated_gross_profit: number | null; contribution_profit: number | null; estimated_profit: number | null };
    daily: DayRow[];
    by_shop: ShopRow[];
    shops: { id: string; kind: 'account' | 'channel'; name: string; marketplace: string }[];
}

export type Metric = 'revenue' | 'awaiting' | 'cancelled' | 'returns' | 'fees' | 'payouts';

export const metricTitles: Record<Metric, string> = {
    revenue: 'Đơn đã ghi nhận doanh thu',
    awaiting: 'Đơn đã giao, chờ sàn hoàn thành',
    cancelled: 'Đơn hủy',
    returns: 'Phiếu trả hàng đã duyệt',
    fees: 'Phí sàn theo đơn',
    payouts: 'Tiền sàn đã chuyển về (theo file đối soát)',
};

export const feeStatusLabels: Record<string, string> = {
    confirmed: 'Đã xác nhận',
    estimated: 'Ước tính',
    unknown: 'Chưa biết',
    none: 'Không có phí sàn',
};

/** How each figure is computed, shown as a tooltip and under the card. */
export const formulas = {
    net_revenue: 'Doanh thu thuần = tiền hàng của đơn đã ghi nhận − giảm giá − thuế GTGT − hàng trả đã duyệt (chưa gồm thuế). Không gồm phí ship khách trả.',
    gross: 'Lợi nhuận gộp = doanh thu thuần − giá vốn (giá vốn lúc xuất kho − giá vốn hàng trả đã nhập lại kho).',
    contribution: 'Lợi nhuận đóng góp = lợi nhuận gộp − phí sàn (đã xác nhận + ước tính) + phí ship khách trả − chi phí bán hàng (phiếu chi nhóm bán hàng).',
    profit: 'Lợi nhuận quản trị = lợi nhuận đóng góp − hao hụt kho − chi phí vận hành và chi phí khác + thu nhập khác. Là số ước tính để quản lý, chưa phải lợi nhuận kế toán.',
    fees: 'Phí sàn đã xác nhận lấy từ file đối soát đã nhập. Đơn chưa có đối soát được ước tính theo bảng phí của shop. Shop chưa có bảng phí thì phí của đơn là “chưa biết”, không tính là 0.',
    payout: 'Tiền sàn đã chuyển về là tiền thu về cho doanh thu đã ghi nhận, không phải doanh thu mới.',
} as const;

/** % change from the previous period; null when there is nothing to compare with. */
export function change(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null || previous === 0) return null;
    return Math.round(((current - previous) / Math.abs(previous)) * 1000) / 10;
}
