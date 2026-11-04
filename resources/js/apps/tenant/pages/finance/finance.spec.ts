import { describe, expect, it } from 'vitest';
import { toCsv } from '../reports/range';
import { change } from './finance';

describe('finance helpers', () => {
    it('computes the change from the previous period, null when there is nothing to compare with', () => {
        expect(change(120, 100)).toBe(20);
        expect(change(-50, -100)).toBe(50);
        expect(change(100, 0)).toBeNull();
        expect(change(null, 100)).toBeNull();
    });

    it('keeps exported text from running as an Excel formula, and leaves numbers alone', () => {
        const csv = toCsv(['Shop'], [['=HYPERLINK("x")'], ['@SUM(A1)'], [-5000], ['Shop A']]);
        expect(csv).toContain(`"'=HYPERLINK(""x"")"`);
        expect(csv).toContain("'@SUM(A1)");
        expect(csv).toContain('\n-5000');
        expect(csv).toContain('\nShop A');
    });
});
