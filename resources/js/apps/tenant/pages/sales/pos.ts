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

export function cartTotals(cart: Cart): { quantity: number; subtotal: number; total: number } {
    const subtotal = cart.lines.reduce((s, l) => s + Math.max(0, (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) - (Number(l.discount) || 0)), 0);
    return {
        quantity: cart.lines.reduce((s, l) => s + (Number(l.quantity) || 0), 0),
        subtotal,
        total: Math.max(0, subtotal - (Number(cart.discount) || 0)),
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
