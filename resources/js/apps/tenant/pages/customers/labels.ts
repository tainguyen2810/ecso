type Tone = 'success' | 'danger' | 'warning' | 'muted' | 'default';

export const genderLabels: Record<string, string> = { male: 'Nam', female: 'Nữ', other: 'Khác' };

export const customerStatus: Record<string, { label: string; tone: Tone }> = {
    active: { label: 'Đang giao dịch', tone: 'success' },
    inactive: { label: 'Ngừng giao dịch', tone: 'muted' },
};

export const priceTierLabels: Record<string, string> = { retail: 'Giá bán lẻ', wholesale: 'Giá bán buôn' };

export const months = Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: `Tháng ${i + 1}` }));

export const customerTypes: Record<string, string> = { individual: 'Cá nhân', company: 'Doanh nghiệp' };

/** Where a customer came from: the channel type of their first order, a referral, or a file import. */
export const customerSources: Record<string, string> = {
    pos: 'Bán tại quầy',
    store: 'Cửa hàng',
    facebook: 'Facebook',
    zalo: 'Zalo',
    website: 'Website',
    phone: 'Điện thoại',
    referral: 'Giới thiệu',
    import: 'Nhập từ file',
    other: 'Khác',
};

export const noteKinds: Record<string, { label: string; tone: Tone }> = {
    note: { label: 'Ghi chú', tone: 'default' },
    call: { label: 'Cuộc gọi', tone: 'success' },
    message: { label: 'Tin nhắn', tone: 'default' },
    meeting: { label: 'Gặp mặt', tone: 'success' },
    complaint: { label: 'Khiếu nại', tone: 'danger' },
};

/** Customer list segments, with the rule each one uses (same figures as the server, Customer::SEGMENTS). */
export const segments: { value: string; label: string; hint: string }[] = [
    { value: 'follow_up', label: 'Cần liên hệ', hint: 'Có việc nhắc đến hạn hôm nay hoặc đã trễ.' },
    { value: 'lapsed', label: 'Lâu không mua', hint: 'Đã từng mua, 90 ngày nay không có đơn.' },
    { value: 'frequent', label: 'Mua thường xuyên', hint: 'Từ 3 đơn trở lên trong 180 ngày.' },
    { value: 'high_value', label: 'Giá trị cao', hint: '10% khách chi tiêu nhiều nhất.' },
    { value: 'new', label: 'Khách mới', hint: 'Thêm trong 30 ngày qua.' },
];
