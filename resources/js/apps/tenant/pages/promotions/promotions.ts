/** Khuyến mãi (C4): types, labels and the preview the order form shows while typing. */
import { api } from '@/shared/lib/api';

export type PromotionType = 'order' | 'product' | 'gift';
export type DiscountKind = 'percent' | 'amount' | 'fixed_price';
export type PromotionState = 'running' | 'scheduled' | 'ended' | 'paused';

export interface PromotionData {
    id: string;
    name: string;
    type: PromotionType;
    code: string | null;
    discount_kind: DiscountKind;
    discount_value: string;
    max_discount: string | null;
    min_subtotal: string;
    tiers: { min_subtotal: string | number; value: string | number }[];
    buy_qty: number | null;
    gift_qty: number | null;
    gift: { id: string; sku: string; name: string } | null;
    channel_ids: string[];
    variant_ids: string[];
    variants: { id: string; sku: string; name: string }[];
    category_ids: string[];
    customer_group_ids: string[];
    starts_at: string | null;
    ends_at: string | null;
    usage_limit: number | null;
    per_customer_limit: number | null;
    status: 'active' | 'paused';
    state: PromotionState;
    uses: number;
    amount: string;
}

export interface PreviewApplied {
    id: string;
    name: string;
    type: PromotionType;
    code: string | null;
    amount: string;
    gifts: { quantity: number; variant_id?: string; sku?: string; name?: string }[];
}

export interface PromotionPreview {
    discount: string;
    applied: PreviewApplied[];
    code_error: string | null;
    /** Tích điểm (C5): the buyer's points and how many this order may use. */
    loyalty?: { enabled: boolean; with_promotions: boolean; balance: number; max: number; point_value: number };
}

/** The four kinds the shop picks from; "Mã giảm giá" is an order discount that needs a code. */
export const promotionKinds = [
    { key: 'order', type: 'order', badge: '%', title: 'Giảm theo đơn', hint: 'Giảm % hoặc số tiền khi đơn đạt mức' },
    { key: 'product', type: 'product', badge: 'SP', title: 'Giảm theo sản phẩm', hint: 'Giá giảm hoặc đồng giá cho SP, danh mục' },
    { key: 'gift', type: 'gift', badge: 'X+Y', title: 'Mua X tặng Y', hint: 'Mua đủ SL thì tặng SP (thêm dòng 0 ₫)' },
    { key: 'code', type: 'order', badge: 'MÃ', title: 'Mã giảm giá', hint: 'Khách đọc mã; giới hạn lượt, mỗi khách 1 lần' },
] as const;

export const stateLabels: Record<PromotionState, { label: string; tone: 'success' | 'info' | 'muted' | 'warning' }> = {
    running: { label: 'Đang chạy', tone: 'success' },
    scheduled: { label: 'Sắp diễn ra', tone: 'info' },
    ended: { label: 'Đã kết thúc', tone: 'muted' },
    paused: { label: 'Tạm dừng', tone: 'warning' },
};

export const typeLabels: Record<PromotionType, string> = { order: 'Giảm theo đơn', product: 'Giảm theo sản phẩm', gift: 'Mua X tặng Y' };

const money = (v: number | string) => `${Math.round(Number(v)).toLocaleString('vi-VN')} ₫`;

/** The promotion in one sentence, for the list and the summary box. */
export function describe(p: Pick<PromotionData, 'type' | 'discount_kind' | 'discount_value' | 'max_discount' | 'min_subtotal' | 'buy_qty' | 'gift_qty'> & { gift?: { name: string } | null }): string {
    const min = Number(p.min_subtotal) > 0 ? `Đơn từ ${money(p.min_subtotal)} ` : '';
    if (p.type === 'gift') return `Mua ${p.buy_qty ?? '?'} tặng ${p.gift_qty ?? 1} ${p.gift?.name ?? 'sản phẩm'}`;
    const value = Number(p.discount_value);
    const off = p.discount_kind === 'percent' ? `${value}%` : p.discount_kind === 'fixed_price' ? `đồng giá ${money(value)}` : money(value);
    const cap = p.discount_kind === 'percent' && Number(p.max_discount) > 0 ? `, tối đa ${money(p.max_discount!)}` : '';
    if (p.type === 'product') return p.discount_kind === 'fixed_price' ? `Sản phẩm áp dụng ${off}` : `Giảm ${off} mỗi sản phẩm áp dụng${cap}`;
    return `${min}${min ? 'được giảm' : 'Giảm'} ${off}${cap}`;
}

export async function previewPromotions(body: Record<string, unknown>): Promise<PromotionPreview> {
    return (await api.post('/promotions/preview', body)).data.data;
}
