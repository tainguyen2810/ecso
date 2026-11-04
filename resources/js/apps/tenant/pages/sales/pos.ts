import { addedTax, saleTax, type TaxRule } from '@/shared/lib/tax';
import type { Variant } from '@/shared/types';

export interface CartLine {
    variant_id: string;
    sku: string;
    name: string;
    quantity: number;
    unit_price: number;
    discount: number;
}

export interface Cart {
    no: number;
    lines: CartLine[];
    customer: { id: string; name: string; phone: string | null; code: string } | null;
    discount: number | string;
    method: 'cash' | 'bank_transfer' | 'card' | 'ewallet';
    /** What the buyer handed over; '' = exact amount. */
    received: number | string;
    /** Idempotency key of the payment in progress: kept until the sale succeeds, so a retry cannot sell twice. */
    key?: string;
    /** Paid with several methods ("Chia nhiều hình thức"): one row per method; null = one method (`method`). */
    split?: SplitRow[] | null;
}

export interface SplitRow {
    method: Cart['method'];
    amount: number | string;
}

/**
 * Payments sent for a split sale: rows with an amount, same method merged. Returns an error when the rows add up to
 * more than the total (no change is given on a split payment).
 */
export function splitPayments(rows: SplitRow[], total: number): { payments: { method: Cart['method']; amount: number }[]; paid: number; error: string | null } {
    const byMethod = new Map<Cart['method'], number>();
    for (const r of rows) {
        const amount = Math.round(Number(r.amount) || 0);
        if (amount > 0) byMethod.set(r.method, (byMethod.get(r.method) ?? 0) + amount);
    }
    const payments = [...byMethod].map(([method, amount]) => ({ method, amount }));
    const paid = payments.reduce((s, p) => s + p.amount, 0);
    return { payments, paid, error: paid > total ? 'Tổng các hình thức thanh toán lớn hơn số khách phải trả.' : null };
}

export function emptyCart(no: number): Cart {
    return { no, lines: [], customer: null, discount: 0, method: 'cash', received: '' };
}

/** Adds one unit: the same SKU scanned twice increases the quantity instead of adding a line. */
export function addToCart(cart: Cart, v: Variant): void {
    const line = cart.lines.find((l) => l.variant_id === v.id);
    if (line) {
        line.quantity += 1;
        return;
    }
    cart.lines.push({ variant_id: v.id!, sku: v.sku, name: (v.product?.name ?? v.sku) + (v.name ? ` · ${v.name}` : ''), quantity: 1, unit_price: Number(v.ref_price) || 0, discount: 0 });
}

/** Totals of a cart; with a tax rule, the VAT on the goods after discount (added to the total when prices exclude it). */
export function cartTotals(cart: Cart, tax?: TaxRule | null): { quantity: number; subtotal: number; tax: number; total: number } {
    const subtotal = cart.lines.reduce((s, l) => s + Math.max(0, (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) - (Number(l.discount) || 0)), 0);
    const goods = Math.max(0, subtotal - (Number(cart.discount) || 0));
    return {
        quantity: cart.lines.reduce((s, l) => s + (Number(l.quantity) || 0), 0),
        subtotal,
        tax: saleTax(goods, tax),
        total: goods + addedTax(goods, tax),
    };
}

/** Banknote suggestions for cash: the exact amount, then round-ups to the common VND notes. */
export function quickCash(total: number): number[] {
    if (total <= 0) return [];
    const options = new Set<number>([total]);
    for (const step of [10000, 50000, 100000, 200000, 500000]) {
        const up = Math.ceil(total / step) * step;
        if (up > total) options.add(up);
    }
    return [...options].sort((a, b) => a - b).slice(0, 5);
}
