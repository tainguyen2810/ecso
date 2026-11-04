import { describe, expect, it } from 'vitest';
import { addToCart, applyGroup, cartTotals, emptyCart, quickCash, splitPayments, syncGroupDiscount } from './pos';
import type { Variant } from '@/shared/types';

const v = (id: string, price: number): Variant => ({ id, sku: id, barcode: null, name: null, options: {}, ref_price: price, weight_gram: null, product: { id: 'p', name: 'Áo' } as never });

describe('POS cart', () => {
    it('merges repeated scans and computes totals with discounts', () => {
        const cart = emptyCart(1);
        addToCart(cart, v('A', 100000));
        addToCart(cart, v('A', 100000));
        addToCart(cart, v('B', 50000));
        cart.lines[1]!.discount = 5000;
        cart.discount = 15000;
        expect(cart.lines).toHaveLength(2);
        expect(cartTotals(cart)).toEqual({ quantity: 3, subtotal: 245000, tax: 0, total: 230000 });
        // VAT is worked out on the goods after the cart discount.
        expect(cartTotals(cart, { rate: 10, included: true })).toEqual({ quantity: 3, subtotal: 245000, tax: 20909, total: 230000 });
        expect(cartTotals(cart, { rate: 10, included: false })).toEqual({ quantity: 3, subtotal: 245000, tax: 23000, total: 253000 });
    });

    it('never goes below zero', () => {
        const cart = emptyCart(1);
        addToCart(cart, v('A', 10000));
        cart.discount = 99999;
        expect(cartTotals(cart).total).toBe(0);
    });

    it('suggests the exact amount and round banknotes', () => {
        expect(quickCash(230000)).toEqual([230000, 250000, 300000, 400000, 500000]);
        expect(quickCash(500000)).toEqual([500000, 600000]);
        expect(quickCash(0)).toEqual([]);
    });
});

describe('customer group on the cart', () => {
    const variant = { id: 'v2', sku: 'B', name: null, ref_price: '100000', wholesale_price: '80000', product: { name: 'Áo' } } as never;

    it('sells at the wholesale price and gives the group discount until a discount is typed', () => {
        const cart = emptyCart(1);
        addToCart(cart, variant);
        cart.lines[0]!.quantity = 2;
        applyGroup(cart, { price_tier: 'wholesale', discount_percent: '5' });
        expect(cart.lines[0]!.unit_price).toBe(80000);
        expect(cart.discount).toBe(8000);
        addToCart(cart, variant);
        syncGroupDiscount(cart);
        expect(cart.discount).toBe(12000);
        cart.discountAuto = false;
        cart.lines[0]!.quantity = 1;
        syncGroupDiscount(cart);
        expect(cart.discount).toBe(12000);
        applyGroup(cart, null);
        expect(cart.lines[0]!.unit_price).toBe(100000);
    });
});

describe('splitPayments', () => {
    it('merges rows of the same method and ignores empty rows', () => {
        const r = splitPayments([{ method: 'cash', amount: 100000 }, { method: 'bank_transfer', amount: '250000' }, { method: 'cash', amount: 50000 }, { method: 'card', amount: '' }], 400000);
        expect(r.payments).toEqual([{ method: 'cash', amount: 150000 }, { method: 'bank_transfer', amount: 250000 }]);
        expect(r.paid).toBe(400000);
        expect(r.error).toBeNull();
    });

    it('refuses more than the total', () => {
        expect(splitPayments([{ method: 'cash', amount: 500000 }], 400000).error).not.toBeNull();
    });
});
