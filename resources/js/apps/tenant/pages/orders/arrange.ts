/** Shopee / TikTok orders waiting for "Chuẩn bị hàng" (GET /orders/to-arrange): one-tap prepare and pick by product. */

export interface ArrangeOrder {
    id: string;
    external_order_id: string;
    code?: string | null;
    status: string;
    source: 'marketplace';
    marketplace_shipping: true;
    /** Never true here: orders Shopee is still checking are not offered for "Chuẩn bị hàng". */
    marketplace_review?: false;
    ship_by_at: string | null;
    /** Kiểu giao: 'express' = Hỏa tốc (these come first). */
    delivery_type?: string | null;
    /** Short of goods (or waiting for goods bought from the supplier). */
    shortage: boolean;
    /** Units of each product in this order, by product key. */
    products: Record<string, number>;
}

export interface ArrangeProduct {
    key: string;
    name: string;
    variant_name: string | null;
    sku: string | null;
    image: string | null;
    mapped: boolean;
    /** Units in stock over all warehouses; null when the line is not linked to a product of the shop. */
    on_hand: number | null;
    /** Units the waiting orders need. */
    quantity: number;
    short: boolean;
    orders: string[];
}

export interface ArrangeSummary {
    count: number;
    urgent: number;
    today: number;
    ready: number;
    orders: ArrangeOrder[];
    products: ArrangeProduct[];
}

export type ArrangeKind = 'all' | 'single' | 'multi';

/** Orders that "Chuẩn bị" on the banner takes: those with the goods, or all of them when none (or all) have it. */
export function bannerIds(summary: ArrangeSummary): string[] {
    const ready = summary.orders.filter((o) => !o.shortage);
    return ready.length ? ready.map((o) => o.id) : summary.orders.map((o) => o.id);
}

export function kindCounts(summary: ArrangeSummary): Record<ArrangeKind, number> {
    const single = summary.orders.filter((o) => productCount(o) <= 1).length;
    return { all: summary.orders.length, single, multi: summary.orders.length - single };
}

/** Products shown in the dialog for the search text and order kind (a product stays while one of its orders fits). */
export function visibleProducts(summary: ArrangeSummary, search: string, kind: ArrangeKind): ArrangeProduct[] {
    const q = search.trim().toLowerCase();
    const fits = new Set(summary.orders.filter((o) => fitsKind(o, kind)).map((o) => o.id));
    return summary.products.filter(
        (p) => p.orders.some((id) => fits.has(id)) && (!q || [p.name, p.variant_name, p.sku].some((t) => t?.toLowerCase().includes(q))),
    );
}

const productCount = (o: ArrangeOrder) => Object.keys(o.products).length;
const fitsKind = (o: ArrangeOrder, kind: ArrangeKind) => kind === 'all' || (kind === 'single' ? productCount(o) <= 1 : productCount(o) > 1);

/** Orders holding any of the picked products, in the order of the summary (soonest deadline first). */
export function pickOrders(summary: ArrangeSummary, picked: Iterable<string>, kind: ArrangeKind, skipShort: boolean): ArrangeOrder[] {
    const keys = new Set(picked);
    return summary.orders.filter((o) => fitsKind(o, kind) && (!skipShort || !o.shortage) && Object.keys(o.products).some((k) => keys.has(k)));
}

/** Units of each picked product the chosen orders need ("lấy 5 keo, 2 card"), biggest first. */
export function pickTotals(summary: ArrangeSummary, picked: Iterable<string>, orders: ArrangeOrder[]): { name: string; quantity: number }[] {
    return [...new Set(picked)]
        .map((key) => {
            const p = summary.products.find((x) => x.key === key);
            const name = p ? [p.name, p.variant_name].filter(Boolean).join(' ') : key;
            return { name, quantity: orders.reduce((n, o) => n + (o.products[key] ?? 0), 0) };
        })
        .filter((t) => t.quantity > 0)
        .sort((a, b) => b.quantity - a.quantity);
}

/** "Còn 2 giờ", "Hôm nay 23:59": the banner's urgency line. */
export function urgencyLine(summary: ArrangeSummary): string[] {
    const parts: string[] = [];
    if (summary.urgent) parts.push(`${summary.urgent} đơn còn dưới 3 giờ`);
    if (summary.today > summary.urgent) parts.push(`${summary.today - summary.urgent} đơn hạn hôm nay`);
    return parts;
}
