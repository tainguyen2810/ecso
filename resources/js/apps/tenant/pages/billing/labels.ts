export type Cycle = 'monthly' | 'yearly';
type Tone = 'default' | 'success' | 'warning' | 'danger' | 'muted';

export const cycleLabels: Record<Cycle, string> = { monthly: 'Theo tháng', yearly: 'Theo năm' };

export const featureLabels: Record<string, string> = {
    'limit.users': 'Người dùng',
    'limit.shops': 'Shop kết nối',
    'limit.warehouses': 'Kho hàng',
    'limit.orders_per_month': 'Đơn mỗi tháng',
    'limit.data_retention_days': 'Lưu dữ liệu (ngày)',
    'reports.advanced': 'Báo cáo nâng cao',
    'automation.rules': 'Tự động hóa',
};

export const subscriptionStatus: Record<string, { label: string; tone: Tone }> = {
    active: { label: 'Đang dùng', tone: 'success' },
    trialing: { label: 'Dùng thử', tone: 'default' },
    past_due: { label: 'Quá hạn thanh toán', tone: 'danger' },
    cancelled: { label: 'Đã hủy', tone: 'muted' },
};

export const invoiceStatus: Record<string, { label: string; tone: Tone }> = {
    pending: { label: 'Chờ thanh toán', tone: 'warning' },
    paid: { label: 'Đã thanh toán', tone: 'success' },
    cancelled: { label: 'Đã hủy', tone: 'muted' },
};

export const invoiceKinds: Record<string, string> = { subscribe: 'Đăng ký', upgrade: 'Nâng cấp', renewal: 'Gia hạn' };

export const paymentStatus: Record<string, { label: string; tone: Tone }> = {
    applied: { label: 'Đã ghi nhận', tone: 'success' },
    underpaid: { label: 'Thiếu tiền', tone: 'warning' },
    unapplied: { label: 'Chưa áp dụng (cần hoàn/đối soát)', tone: 'danger' },
};

/** Gross margin in percent, or null when revenue is zero. */
export function marginPercent(profit: number, revenue: number): number | null {
    return revenue > 0 ? Math.round((profit / revenue) * 1000) / 10 : null;
}
