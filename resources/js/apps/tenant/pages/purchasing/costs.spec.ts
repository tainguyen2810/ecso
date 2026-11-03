import { describe, expect, it } from 'vitest';
import { purchaseCosts } from './costs';

describe('purchaseCosts', () => {
    it('adds VAT per line and spreads costs by value, like the server', () => {
        const r = purchaseCosts(
            [
                { quantity: 10, unit_cost: 100000, tax_rate: 10 },
                { quantity: 20, unit_cost: 50000, tax_rate: 8 },
            ],
            { extra_cost: 200000, extra_cost_to_supplier: false, vat_in_cost: false },
        );
        expect(r).toMatchObject({ subtotal: 2000000, tax: 180000, total: 2180000, quantity: 30, landed: [110000, 55000] });
    });

    it('carries VAT in cost and owes freight on the supplier invoice', () => {
        const r = purchaseCosts([{ quantity: 4, unit_cost: 25000, tax_rate: 10 }], { extra_cost: 20000, extra_cost_to_supplier: true, vat_in_cost: true });
        expect(r.total).toBe(130000);
        expect(r.landed).toEqual([32500]);
    });

    it('takes the order discount off the total and the landed cost by value', () => {
        const r = purchaseCosts(
            [
                { quantity: 10, unit_cost: 100000, tax_rate: 0 },
                { quantity: 20, unit_cost: 50000, tax_rate: 0 },
            ],
            { discount: 100000, extra_cost: 0, extra_cost_to_supplier: false, vat_in_cost: false },
        );
        expect(r).toMatchObject({ total: 1900000, discount: 100000, landed: [95000, 47500] });
    });

    it('spreads costs by quantity when the goods are free', () => {
        expect(purchaseCosts([{ quantity: 4, unit_cost: 0, tax_rate: 0 }], { extra_cost: 2000, extra_cost_to_supplier: false, vat_in_cost: false }).landed).toEqual([500]);
    });
});
