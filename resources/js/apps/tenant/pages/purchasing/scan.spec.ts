import { describe, expect, it } from 'vitest';
import { buildDraft, priceRise, reviewLines, skuFromLine, type ScanResult } from './scan';

const v = (id: string, cost: number | null = 100) => ({ id, sku: id.toUpperCase(), name: `SP ${id}`, score: 1, cost_price: cost });
const result: ScanResult = {
    supplier: { read: { name: 'Linh kiện Hoàng Gia', tax_code: '0316789123', address: '', phone: '' }, match: null },
    invoice: { number: '002871', series: 'C26TLH', date: '2026-11-20' },
    lines: [
        { name: 'Card wifi AX210', code: 'AX210', unit: 'Cái', quantity: 10, unit_price: 385000, amount: 3850000, status: 'matched', variant: v('a', 392000), candidates: [] },
        { name: 'Card wifi AX210 (thêm)', code: '', unit: 'Cái', quantity: 2, unit_price: 395000, amount: 790000, status: 'maybe', variant: v('a', 392000), candidates: [] },
        { name: 'Chuột M331', code: 'M331', unit: 'Cái', quantity: 15, unit_price: 265000, amount: 3975000, status: 'new', variant: null, candidates: [] },
    ],
    discount: 105000, shipping: 30000, vat_amount: 0, vat_rate: 8, total: 0, sum: 0, total_matches: null, unreadable: '',
};

describe('Chụp hoá đơn nhập hàng', () => {
    it('keeps only lines with a product and merges the same SKU', () => {
        const lines = reviewLines(result.lines);
        expect(lines[2]!.pick).toBeNull();
        const d = buildDraft(result, lines, 'sup', 'wh');
        expect(d.lines).toEqual([{ id: 'a', sku: 'A', name: 'SP a', quantity: 12, unit_cost: Math.round((10 * 385000 + 2 * 395000) / 12) }]);
        expect(d.reference).toBe('HĐ C26TLH 002871');
        expect(d).toMatchObject({ supplier_id: 'sup', warehouse_id: 'wh', invoice_date: '2026-11-20', discount: 105000, extra_cost: 30000, tax_rate: 8 });
    });

    it('leaves out unticked lines', () => {
        const lines = reviewLines(result.lines);
        lines[0]!.include = false;
        expect(buildDraft(result, lines, 's', 'w').lines[0]!.quantity).toBe(2);
    });

    it('shows how much dearer the invoice is than the cost', () => {
        const [a] = reviewLines([{ ...result.lines[0]!, unit_price: 412000, variant: v('a', 400000) }]);
        expect(priceRise(a!)).toBe(3);
        expect(priceRise({ ...a!, pick: v('b', null) })).toBeNull();
    });

    it('makes a SKU from the supplier code or the model in the name', () => {
        expect(skuFromLine({ ...result.lines[2]!, code: 'm331 bk' })).toBe('M331-BK');
        expect(skuFromLine({ ...result.lines[2]!, code: '', name: 'SSD WD SN580 512GB' })).toBe('SN580-512GB');
        expect(skuFromLine({ ...result.lines[2]!, code: '', name: 'Dây đồng hồ da' })).toBe('DDHD');
    });
});
