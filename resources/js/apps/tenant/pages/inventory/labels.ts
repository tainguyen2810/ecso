export const movementLabels: Record<string, { label: string; tone: 'success' | 'danger' | 'warning' | 'muted' | 'default' }> = {
    OPENING: { label: 'Tồn đầu kỳ', tone: 'success' },
    RECEIPT: { label: 'Nhập kho', tone: 'success' },
    RESERVE: { label: 'Giữ hàng', tone: 'warning' },
    RELEASE: { label: 'Nhả giữ', tone: 'muted' },
    SHIP: { label: 'Xuất bán', tone: 'danger' },
    SHIP_UNRESERVED: { label: 'Xuất không giữ', tone: 'danger' },
    RETURN_RESTOCK: { label: 'Hoàn nhập kho', tone: 'success' },
    DAMAGE_OUT: { label: 'Xuất hủy', tone: 'danger' },
    ADJUSTMENT_IN: { label: 'Điều chỉnh tăng', tone: 'default' },
    ADJUSTMENT_OUT: { label: 'Điều chỉnh giảm', tone: 'default' },
    TRANSFER_OUT: { label: 'Chuyển đi', tone: 'warning' },
    TRANSFER_IN: { label: 'Chuyển đến', tone: 'success' },
    SUPPLIER_RETURN: { label: 'Trả NCC', tone: 'danger' },
    RESERVATION_REALLOCATE: { label: 'Đổi kho giữ', tone: 'muted' },
};

export const referenceLabels: Record<string, string> = {
    StockReceipt: 'Phiếu nhập',
    InventoryAdjustment: 'Phiếu điều chỉnh',
    StockTransfer: 'Phiếu chuyển',
    StockReservation: 'Giữ hàng',
};

export const adjustmentReasons: Record<string, string> = {
    stocktake: 'Kiểm kê',
    damaged: 'Hàng hỏng',
    lost: 'Thất lạc',
    found: 'Tìm thấy',
    correction: 'Sửa sai sót',
    other: 'Khác',
};

export const documentStatus: Record<string, { label: string; tone: 'success' | 'danger' | 'warning' | 'muted' | 'default' }> = {
    pending: { label: 'Chờ duyệt', tone: 'warning' },
    approved: { label: 'Đã duyệt', tone: 'success' },
    rejected: { label: 'Từ chối', tone: 'danger' },
    cancelled: { label: 'Đã hủy', tone: 'muted' },
    draft: { label: 'Nháp', tone: 'muted' },
    in_transit: { label: 'Đang chuyển', tone: 'warning' },
    received: { label: 'Đã nhận', tone: 'success' },
};

export const documentKinds = {
    receipts: { kind: 'receipt', title: 'Phiếu nhập kho', create: 'Tạo phiếu nhập', permission: 'inventory.receive' },
    adjustments: { kind: 'adjustment', title: 'Phiếu điều chỉnh', create: 'Tạo phiếu điều chỉnh', permission: 'inventory.adjust' },
    transfers: { kind: 'transfer', title: 'Phiếu chuyển kho', create: 'Tạo phiếu chuyển', permission: 'inventory.transfer' },
} as const;

export type DocumentKindKey = keyof typeof documentKinds;
