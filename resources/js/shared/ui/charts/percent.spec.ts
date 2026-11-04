import { describe, expect, it } from 'vitest';
import { wholePercents } from './percent';

describe('wholePercents', () => {
    it('always adds up to 100', () => {
        expect(wholePercents([124, 12, 9, 2, 4, 0])).toEqual([82, 8, 6, 1, 3, 0]);
        expect(wholePercents([1, 1, 1])).toEqual([34, 33, 33]);
        for (const values of [[1, 1, 1], [2, 3, 5, 7], [10, 0, 1]]) {
            expect(wholePercents(values).reduce((s, v) => s + v, 0)).toBe(100);
        }
    });
    it('returns zeros when there is nothing', () => {
        expect(wholePercents([0, 0])).toEqual([0, 0]);
    });
});
