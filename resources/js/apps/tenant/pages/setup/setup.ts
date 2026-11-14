/** A2: answers of the setup steps after sign-up (same keys as SetupController on the server). */
export const INDUSTRIES: { value: string; label: string }[] = [
    { value: 'fashion', label: 'Thời trang' },
    { value: 'cosmetics', label: 'Mỹ phẩm' },
    { value: 'mom_baby', label: 'Mẹ & bé' },
    { value: 'electronics', label: 'Điện thoại, phụ kiện' },
    { value: 'food', label: 'Thực phẩm' },
    { value: 'household', label: 'Gia dụng' },
    { value: 'books', label: 'Sách, văn phòng phẩm' },
    { value: 'other', label: 'Khác' },
];

export type ChannelOption = { value: string; label: string; detail: string; logo: string };

export const CHANNELS: ChannelOption[] = [
    { value: 'shopee', label: 'Shopee', detail: 'Kết nối shop để kéo đơn và đồng bộ tồn.', logo: 'shopee' },
    { value: 'tiktok', label: 'TikTok Shop', detail: 'Kết nối shop để kéo đơn và đồng bộ tồn.', logo: 'tiktok' },
    { value: 'woocommerce', label: 'Website WooCommerce', detail: 'Đơn web về EzSell, tồn đẩy ngược lên web.', logo: 'woocommerce' },
    { value: 'pos', label: 'Bán tại cửa hàng', detail: 'Màn hình bán quầy, in hóa đơn, ca thu ngân.', logo: 'manual' },
    { value: 'social', label: 'Facebook, Zalo, điện thoại', detail: 'Tạo đơn tay, gửi tin Zalo cho khách.', logo: 'zalo' },
    { value: 'lazada_tiki', label: 'Lazada, Tiki', detail: 'Kết nối khi sàn mở API cho shop.', logo: 'lazada' },
];

export const TEAM_SIZES: { value: string; label: string }[] = [
    { value: '1', label: 'Chỉ mình tôi' },
    { value: '2-5', label: '2 – 5 người' },
    { value: '6-20', label: '6 – 20 người' },
    { value: '20+', label: 'Trên 20' },
];

export type StartChoice = 'demo' | 'migrate' | 'empty';

/** Where the last step leads. */
export function startTarget(choice: StartChoice): string {
    return choice === 'migrate' ? '/settings/data-migration' : '/';
}

/** The POS menu is hidden only when the shop answered where it sells and left out "Bán tại cửa hàng". */
export function hidesPos(channels: string[] | null | undefined): boolean {
    return Array.isArray(channels) && channels.length > 0 && !channels.includes('pos');
}

/** Days left of a sample store, at least 0. */
export function demoDaysLeft(expiresAt: string | null | undefined, now: Date = new Date()): number {
    if (!expiresAt) return 0;
    return Math.max(0, Math.ceil((new Date(expiresAt).getTime() - now.getTime()) / 86_400_000));
}
