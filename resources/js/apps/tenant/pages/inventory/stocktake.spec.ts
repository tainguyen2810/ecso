import { describe, expect, it } from 'vitest';
import { applyScan, stocktakeLines, variance, type CountRow } from './stocktake';

const rows: CountRow[] = [
    { id: 'a', sku: 'ATC-TRANG-M', barcode: '8930001', name: 'Áo', on_hand: 5 },
    { id: 'b', sku: 'QJ-31', barcode: null, name: 'Quần', on_hand: 2 },
    { id: 'c', sku: 'NON', barcode: null, name: 'Nón', on_hand: 0 },
];

describe('stocktake', () => {
    it('adds one per scan by barcode or SKU', () => {
        const counts: Record<string, number | ''> = {};
        expect(applyScan(rows, counts, '8930001')).toBe('a');
        expect(applyScan(rows, counts, 'atc-trang-m')).toBe('a');
        expect(applyScan(rows, counts, 'nope')).toBeNull();
        expect(counts).toEqual({ a: 2 });
        expect(variance(rows[0]!, counts)).toBe(-3);
        expect(variance(rows[1]!, counts)).toBeNull();
    });
    it('builds count-mode lines, optionally zeroing uncounted SKUs that have stock', () => {
        const counts = { a: 4 as number | '' };
        expect(stocktakeLines(rows, counts, false)).toEqual([{ variant_id: 'a', mode: 'count', quantity: 4, system_qty: 5 }]);
        expect(stocktakeLines(rows, counts, true)).toEqual([
            { variant_id: 'a', mode: 'count', quantity: 4, system_qty: 5 },
            { variant_id: 'b', mode: 'count', quantity: 0, system_qty: 2 },
        ]);
    });
    it('compares a saved line with the stock seen when it was counted', () => {
        const saved: CountRow = { id: 'a', sku: 'ATC-TRANG-M', barcode: null, name: 'Áo', on_hand: 50, on_hand_now: 45 };
        expect(variance(saved, { a: 48 })).toBe(-2);
        expect(stocktakeLines([saved], { a: 48 }, false)).toEqual([{ variant_id: 'a', mode: 'count', quantity: 48, system_qty: 50 }]);
    });
    it('sends the reason and note only with lines that differ', () => {
        const counts = { a: 4 as number | '', b: 2 as number | '' };
        const notes = { a: { reason: 'damaged', note: 'Vỡ hộp' }, b: { reason: 'lost' } };
        expect(stocktakeLines(rows, counts, false, notes)).toEqual([
            { variant_id: 'a', mode: 'count', quantity: 4, system_qty: 5, reason: 'damaged', note: 'Vỡ hộp' },
            { variant_id: 'b', mode: 'count', quantity: 2, system_qty: 2 },
        ]);
    });
});
