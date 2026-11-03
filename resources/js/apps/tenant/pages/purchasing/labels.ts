type Tone = 'success' | 'danger' | 'warning' | 'muted' | 'default';

export const poStatus: Record<string, { label: string; tone: Tone }> = {
    draft: { label: 'Nháp', tone: 'muted' },
    ordered: { label: 'Đã đặt hàng', tone: 'default' },
    partial: { label: 'Nhận một phần', tone: 'warning' },
    received: { label: 'Đã nhận đủ', tone: 'success' },
    closed: { label: 'Đã đóng', tone: 'muted' },
    cancelled: { label: 'Đã hủy', tone: 'danger' },
};

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
