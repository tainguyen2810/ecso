/** "Chụp hoá đơn nhập hàng": what the server returns and the hand-off to "Tạo đơn nhập hàng" (sessionStorage). */
export const SCAN_DRAFT_KEY = 'ecso.purchasing.scan-draft';

export interface ScanVariant {
    id: string;
    sku: string;
    name: string;
    score: number;
    cost_price: number | null;
}

export interface ScanLine {
    name: string;
    code: string;
    unit: string;
    quantity: number;
    unit_price: number;
    amount: number;
    status: 'matched' | 'maybe' | 'new';
    variant: ScanVariant | null;
    candidates: ScanVariant[];
}

export interface ScanResult {
    supplier: { read: { name: string; tax_code: string; address: string; phone: string }; match: { id: string; name: string } | null };
    invoice: { number: string; series: string; date: string | null };
    lines: ScanLine[];
    discount: number;
    shipping: number;
    vat_amount: number;
    vat_rate: number | null;
    total: number;
    sum: number;
    total_matches: boolean | null;
    unreadable: string;
}

/** A line on the review screen: the server reading plus what the user picked. */
export interface ReviewLine extends ScanLine {
    include: boolean;
    pick: ScanVariant | null;
}

export interface ScanDraft {
    supplier_id: string;
    warehouse_id: string;
    invoice_date: string;
    reference: string;
    discount: number;
    extra_cost: number;
    tax_rate: number | null;
    note: string;
    lines: { id: string; sku: string; name: string; quantity: number; unit_cost: number }[];
    /** Made from "Hoá đơn mua vào": the invoice gets linked to the purchase order. */
    purchase_invoice_id?: string | null;
    /** "Nhớ cách ghép": the supplier's item code for each picked SKU. */
    supplier_codes?: { id: string; code: string }[];
}

export function reviewLines(lines: ScanLine[]): ReviewLine[] {
    return lines.map((l) => ({ ...l, include: true, pick: l.status === 'new' ? null : l.variant }));
}

/** Rise of the invoice price over the current cost, in % (null when there is no cost to compare). */
export function priceRise(line: ReviewLine): number | null {
    const cost = line.pick?.cost_price;
    if (!cost || cost <= 0 || !line.unit_price) return null;
    return Math.round(((line.unit_price - cost) / cost) * 100);
}

export function invoiceReference(inv: ScanResult['invoice']): string {
    return [inv.series, inv.number].filter(Boolean).join(' ').trim();
}

/** Lines picked for the order; the same SKU twice becomes one line (quantities added, price weighted). */
export function buildDraft(result: ScanResult, lines: ReviewLine[], supplierId: string, warehouseId: string, invoiceId: string | null = null): ScanDraft {
    const merged = new Map<string, { id: string; sku: string; name: string; quantity: number; total: number }>();
    for (const l of lines) {
        if (!l.include || !l.pick || l.quantity <= 0) continue;
        const m = merged.get(l.pick.id) ?? { id: l.pick.id, sku: l.pick.sku, name: l.pick.name, quantity: 0, total: 0 };
        m.quantity += l.quantity;
        m.total += l.quantity * l.unit_price;
        merged.set(l.pick.id, m);
    }
    const ref = invoiceReference(result.invoice);
    return {
        supplier_id: supplierId,
        warehouse_id: warehouseId,
        invoice_date: result.invoice.date ?? '',
        reference: ref ? `HĐ ${ref}`.slice(0, 100) : '',
        discount: Math.round(result.discount),
        extra_cost: Math.round(result.shipping),
        tax_rate: result.vat_rate,
        note: invoiceId ? 'Tạo từ hoá đơn điện tử.' : 'Tạo từ ảnh hoá đơn (AI đọc).',
        lines: [...merged.values()].map((m) => ({ id: m.id, sku: m.sku, name: m.name, quantity: m.quantity, unit_cost: Math.round(m.total / m.quantity) })),
        purchase_invoice_id: invoiceId,
        supplier_codes: supplierCodes(lines),
    };
}

/** The supplier's code printed for each picked SKU (first one wins), so the next invoice matches at once. */
export function supplierCodes(lines: ReviewLine[]): { id: string; code: string }[] {
    const out = new Map<string, string>();
    for (const l of lines) {
        const code = l.code.trim();
        if (l.include && l.pick && code && !out.has(l.pick.id)) out.set(l.pick.id, code.slice(0, 64));
    }
    return [...out].map(([id, code]) => ({ id, code }));
}

/** SKU for a product created from an invoice line: the supplier's code when it has one. */
export function skuFromLine(line: ScanLine): string {
    const code = line.code.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').replace(/[^A-Za-z0-9._/-]+/g, '-').replace(/^-+|-+$/g, '').toUpperCase();
    if (code.length >= 2) return code.slice(0, 60);
    const words = line.name.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toUpperCase().split(/[^A-Z0-9]+/).filter(Boolean);
    const withDigits = words.filter((w) => /\d/.test(w));
    return (withDigits.length ? withDigits.slice(0, 2).join('-') : words.slice(0, 4).map((w) => w[0]).join('')) || 'SP';
}
