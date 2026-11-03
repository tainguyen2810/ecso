/** Vietnamese VAT rates offered on purchase lines. */
export const VAT_RATES = [0, 5, 8, 10] as const;

export interface CostLine {
    quantity: number | string;
    unit_cost: number | string;
    tax_rate: number | string;
}

export interface CostOptions {
    extra_cost: number | string;
    extra_cost_to_supplier: boolean;
    vat_in_cost: boolean;
}

/**
 * Same arithmetic as PurchaseService on the server: VAT per line, purchase costs spread over the goods by value
 * (by quantity when everything is free), landed cost per unit = price (+ VAT when carried in cost) + cost share.
 */
export function purchaseCosts(lines: CostLine[], opts: CostOptions) {
    const n = (v: number | string) => Number(v) || 0;
    const extra = n(opts.extra_cost);
    const subtotal = lines.reduce((s, l) => s + n(l.quantity) * n(l.unit_cost), 0);
    const units = lines.reduce((s, l) => s + n(l.quantity), 0);
    const tax = lines.reduce((s, l) => s + (n(l.quantity) * n(l.unit_cost) * n(l.tax_rate)) / 100, 0);
    const landed = lines.map((l) => {
        const share = extra <= 0 ? 0 : subtotal > 0 ? (extra * n(l.unit_cost)) / subtotal : units > 0 ? extra / units : 0;
        return n(l.unit_cost) + (opts.vat_in_cost ? (n(l.unit_cost) * n(l.tax_rate)) / 100 : 0) + share;
    });

    return {
        subtotal: Math.round(subtotal),
        tax: Math.round(tax),
        total: Math.round(subtotal + tax + (opts.extra_cost_to_supplier ? extra : 0)),
        quantity: units,
        landed: landed.map((v) => Math.round(v)),
    };
}
