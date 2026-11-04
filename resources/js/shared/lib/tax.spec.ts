import { describe, expect, it } from 'vitest';
import { addedTax, saleTax } from './tax';

describe('saleTax', () => {
    it('is nothing without a rate', () => {
        expect(saleTax(100000, { rate: 0, included: true })).toBe(0);
        expect(saleTax(100000, null)).toBe(0);
    });
    it('takes the tax out of prices that include it, rounded to the đồng', () => {
        expect(saleTax(110000, { rate: 10, included: true })).toBe(10000);
        expect(saleTax(99999, { rate: 8, included: true })).toBe(7407);
        expect(addedTax(110000, { rate: 10, included: true })).toBe(0);
    });
    it('adds the tax on top of prices before tax', () => {
        expect(saleTax(99995, { rate: 10, included: false })).toBe(10000);
        expect(addedTax(99995, { rate: 10, included: false })).toBe(10000);
    });
});
