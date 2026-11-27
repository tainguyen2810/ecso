/**
 * Mã đơn hàng (Cấu hình › Mã đơn hàng): the shop's own number (SON50892) is the order's main code; the marketplace /
 * Woo number, the old SO-… number and a code typed by hand are secondary codes shown under it. Before the shop starts
 * its numbering an order has no code and its marketplace / old number stays the main one.
 */
export type CodedOrder = {
    code?: string | null;
    external_order_id: string;
    external_number?: string | null;
    reference?: string | null;
    marketplace?: string;
    imported_from?: string | null;
};

export type SecondaryCode = { kind: 'marketplace' | 'woo' | 'old' | 'reference'; label: string; value: string };

const MARKETPLACE_LABELS: Record<string, string> = { shopee: 'Shopee', tiktok: 'TikTok', lazada: 'Lazada', tiki: 'Tiki', woocommerce: 'Woo' };

export function orderCode(o: { code?: string | null; external_order_id?: string | null } | null | undefined): string {
    if (!o) return '';
    return o.code || o.external_order_id || '';
}

export function secondaryCodes(o: CodedOrder): SecondaryCode[] {
    const out: SecondaryCode[] = [];
    const main = orderCode(o);
    const manual = !o.marketplace || o.marketplace === 'manual';
    if (o.marketplace === 'woocommerce' && o.external_number) {
        out.push({ kind: 'woo', label: 'Woo', value: `#${o.external_number}` });
    } else if (o.external_order_id && o.external_order_id !== main) {
        out.push(manual ? { kind: 'old', label: 'Mã cũ', value: o.external_order_id } : { kind: 'marketplace', label: MARKETPLACE_LABELS[o.marketplace ?? ''] ?? 'Sàn', value: o.external_order_id });
    } else if (o.external_number && o.external_number !== main) {
        out.push({ kind: 'woo', label: 'Woo', value: `#${o.external_number}` });
    }
    if (o.reference && o.reference !== main && !out.some((s) => s.value === o.reference)) {
        out.push({ kind: 'reference', label: 'Tham chiếu', value: o.reference });
    }
    return out;
}
