import type { Variant } from '@/shared/types';

export interface CartLine {
    variant_id: string;
    sku: string;
    name: string;
    quantity: number;
    unit_price: number;
    discount: number;
    /** List prices, to switch the line when the buyer's group sells at another price. */
    retail?: number;
    wholesale?: number;
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
    /** Price policy and order discount (%) of the buyer's group; `discountAuto` until someone types a discount. */
    tier?: 'retail' | 'wholesale';
    groupPercent?: number;
    discountAuto?: boolean;
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
    const retail = Number(v.ref_price) || 0;
    const wholesale = Number(v.wholesale_price) || 0;
    cart.lines.push({ variant_id: v.id!, sku: v.sku, name: (v.product?.name ?? v.sku) + (v.name ? ` · ${v.name}` : ''), quantity: 1, unit_price: linePrice(cart, { retail, wholesale }), discount: 0, retail, wholesale });
}

/** The list price for the cart's price policy: wholesale when the group buys wholesale and the SKU has one. */
export function linePrice(cart: Cart, l: { retail?: number; wholesale?: number }): number {
    return cart.tier === 'wholesale' && (l.wholesale ?? 0) > 0 ? l.wholesale! : (l.retail ?? 0);
}

/** Applies the buyer's group to the cart: price policy on every line, and its discount on the cart. */
export function applyGroup(cart: Cart, group: { price_tier: 'retail' | 'wholesale'; discount_percent: string } | null | undefined): void {
    cart.tier = group?.price_tier ?? 'retail';
    cart.lines.forEach((l) => l.retail !== undefined && (l.unit_price = linePrice(cart, l)));
    cart.groupPercent = Number(group?.discount_percent ?? 0);
    cart.discountAuto = cart.groupPercent > 0;
    syncGroupDiscount(cart);
}

/** Keeps the group discount in step with the cart while it is automatic. */
export function syncGroupDiscount(cart: Cart): void {
    if (!cart.discountAuto) return;
    const subtotal = cartTotals({ ...cart, discount: 0 }).subtotal;
    cart.discount = Math.round((subtotal * (cart.groupPercent ?? 0)) / 100);
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
