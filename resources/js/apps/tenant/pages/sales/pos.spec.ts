import { describe, expect, it } from 'vitest';
import { addToCart, cartTotals, emptyCart, quickCash, splitPayments } from './pos';
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
        expect(cartTotals(cart)).toEqual({ quantity: 3, subtotal: 245000, total: 230000 });
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
