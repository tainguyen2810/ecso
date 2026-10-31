type Tone = 'success' | 'danger' | 'warning' | 'muted' | 'default';

export const orderStatus: Record<string, { label: string; tone: Tone }> = {
    UNPAID: { label: 'Chờ thanh toán', tone: 'muted' },
    PENDING_CONFIRMATION: { label: 'Chờ xác nhận', tone: 'warning' },
    READY_TO_PACK: { label: 'Chờ đóng gói', tone: 'warning' },
    PACKED: { label: 'Chờ bàn giao', tone: 'default' },
    HANDED_OVER: { label: 'Đã bàn giao', tone: 'default' },
    IN_TRANSIT: { label: 'Đang giao', tone: 'default' },
    DELIVERED: { label: 'Đã giao', tone: 'success' },
    COMPLETED: { label: 'Hoàn tất', tone: 'success' },
    CANCELLED: { label: 'Đã hủy', tone: 'muted' },
    FAILED_DELIVERY: { label: 'Giao thất bại', tone: 'danger' },
    RETURN_REQUESTED: { label: 'Yêu cầu trả', tone: 'danger' },
    RETURNED: { label: 'Đã hoàn', tone: 'danger' },
    UNKNOWN_STATUS: { label: 'Chưa rõ trạng thái', tone: 'danger' },
};

export const orderTabs: { value: string; label: string }[] = [
    { value: 'all', label: 'Tất cả' },
    { value: 'new', label: 'Chờ thanh toán' },
    { value: 'to_confirm', label: 'Chờ xác nhận' },
    { value: 'to_pack', label: 'Chờ đóng gói' },
    { value: 'to_handover', label: 'Chờ bàn giao' },
    { value: 'shipping', label: 'Đang giao' },
    { value: 'done', label: 'Hoàn tất' },
    { value: 'cancelled', label: 'Đã hủy' },
    { value: 'returns', label: 'Hoàn/Trả' },
    { value: 'unknown', label: 'Cần kiểm tra' },
];

export const orderFlags: { value: string; label: string }[] = [
    { value: 'overdue', label: 'Quá hạn giao' },
    { value: 'due_soon', label: 'Sắp đến hạn' },
    { value: 'shortage', label: 'Thiếu hàng' },
    { value: 'unmapped', label: 'Chưa map SKU' },
    { value: 'warning', label: 'Cảnh báo đồng bộ' },
    { value: 'not_printed', label: 'Chưa in phiếu' },
];

export const actionLabels: Record<string, string> = {
    confirm: 'Xác nhận',
    pack: 'Đóng gói',
    handover: 'Bàn giao vận chuyển',
    cancel: 'Hủy đơn',
};

export const capabilityLabels: Record<string, { label: string; tone: Tone }> = {
    supported: { label: 'Hỗ trợ', tone: 'success' },
    unsupported: { label: 'Không hỗ trợ', tone: 'danger' },
    unknown: { label: 'UNKNOWN', tone: 'muted' },
};

export const capabilityNames: Record<string, string> = {
    'orders.webhook': 'Webhook đơn hàng',
    'orders.fetch': 'Lấy chi tiết đơn',
    'orders.list': 'Liệt kê đơn (đối soát)',
    'orders.confirm': 'Xác nhận đơn',
    'orders.pack': 'Đóng gói',
    'orders.handover': 'Bàn giao vận chuyển',
    'orders.cancel': 'Hủy đơn',
    'shipping.label': 'In vận đơn',
    'products.list': 'Lấy danh sách sản phẩm',
    'inventory.update': 'Cập nhật tồn kho',
    'inventory.get': 'Đọc tồn trên sàn',
    'returns.list': 'Lấy yêu cầu trả hàng',
};

export const historySource: Record<string, string> = {
    webhook: 'Webhook',
    user: 'Người dùng',
    reconcile: 'Đối soát',
    poll: 'Đối soát',
    sync: 'Đồng bộ',
    system: 'Hệ thống',
};

export const returnTypes: Record<string, string> = {
    return: 'Khách trả hàng',
    refund_only: 'Chỉ hoàn tiền',
    cancel_after_ship: 'Hủy sau khi gửi',
    failed_delivery: 'Giao thất bại',
};

export const returnStatus: Record<string, { label: string; tone: Tone }> = {
    requested: { label: 'Mới yêu cầu', tone: 'warning' },
    approved: { label: 'Đã duyệt', tone: 'default' },
    in_transit: { label: 'Đang hoàn về', tone: 'warning' },
    received: { label: 'Đã nhận, chờ kiểm', tone: 'danger' },
    inspected: { label: 'Đã kiểm', tone: 'success' },
    closed: { label: 'Đã đóng', tone: 'muted' },
    rejected: { label: 'Từ chối', tone: 'muted' },
};

export const marketplaceNames: Record<string, string> = {
    shopee: 'Shopee',
    tiktok: 'TikTok Shop',
    lazada: 'Lazada',
    tiki: 'Tiki',
    fake: 'Sàn giả lập',
};

export const mappingStatus: Record<string, { label: string; tone: Tone }> = {
    mapped: { label: 'Đã map', tone: 'success' },
    suggested: { label: 'Gợi ý', tone: 'warning' },
    unmapped: { label: 'Chưa map', tone: 'danger' },
    ignored: { label: 'Bỏ qua', tone: 'muted' },
};

export const pushStatus: Record<string, { label: string; tone: Tone }> = {
    ok: { label: 'Đã đẩy', tone: 'success' },
    pending: { label: 'Chờ đẩy', tone: 'warning' },
    retrying: { label: 'Đang thử lại', tone: 'warning' },
    rejected: { label: 'Sàn từ chối', tone: 'danger' },
    failed: { label: 'Lỗi', tone: 'danger' },
    unsupported: { label: 'Sàn không hỗ trợ', tone: 'muted' },
};

/** Hours left until the ship-by deadline, as a short label ("còn 3 giờ", "quá 2 giờ"). */
export function deadlineLabel(iso: string | null, now = new Date()): string | null {
    if (!iso) return null;
    const minutes = Math.round((new Date(iso).getTime() - now.getTime()) / 60000);
    const abs = Math.abs(minutes);
    const text = abs < 60 ? `${abs} phút` : abs < 48 * 60 ? `${Math.round(abs / 60)} giờ` : `${Math.round(abs / 1440)} ngày`;
    return minutes >= 0 ? `còn ${text}` : `quá ${text}`;
}
