type Tone = 'success' | 'danger' | 'warning' | 'muted' | 'default' | 'info';

export const poStatus: Record<string, { label: string; tone: Tone }> = {
    draft: { label: 'Nháp', tone: 'muted' },
    ordered: { label: 'Đã đặt hàng', tone: 'default' },
    partial: { label: 'Nhận một phần', tone: 'warning' },
    received: { label: 'Đã nhận đủ', tone: 'success' },
    closed: { label: 'Đã đóng', tone: 'muted' },
    cancelled: { label: 'Đã hủy', tone: 'danger' },
};

/** Sapo's two columns: the deal (Đang giao dịch / Hoàn thành / Đã hủy) and the goods (Chưa nhập / Nhập một phần / Đã nhập). */
export function tradeStatus(status: string): { label: string; tone: Tone } {
    if (status === 'cancelled') return { label: 'Đã hủy', tone: 'danger' };
    if (status === 'draft') return { label: 'Nháp', tone: 'muted' };
    return status === 'received' || status === 'closed' ? { label: 'Hoàn thành', tone: 'success' } : { label: 'Đang giao dịch', tone: 'warning' };
}

export function receiveStatus(status: string): { label: string; tone: Tone } {
    if (status === 'partial') return { label: 'Nhập một phần', tone: 'info' };
    if (status === 'received') return { label: 'Đã nhập', tone: 'success' };
    if (status === 'closed') return { label: 'Đã nhập, đóng đơn', tone: 'success' };
    return { label: 'Chưa nhập', tone: 'muted' };
}

export const supplierPaymentMethods: Record<string, string> = {
    cash: 'Tiền mặt',
    bank_transfer: 'Chuyển khoản',
    card: 'Thẻ',
    other: 'Khác',
};

/** What the supplier is still owed on one order: received − returned − paid. */
export function owed(po: { received_value: string; returned_value: string; paid_amount: string }): number {
    return Number(po.received_value) - Number(po.returned_value) - Number(po.paid_amount);
}
