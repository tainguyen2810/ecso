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
