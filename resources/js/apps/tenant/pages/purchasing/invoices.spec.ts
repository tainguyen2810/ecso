import { describe, expect, it } from 'vitest';
import { monthOptions } from './invoices';
import { buildDraft, reviewLines, supplierCodes, type ScanResult } from './scan';

describe('monthOptions', () => {
    it('counts back across the new year', () => {
        const m = monthOptions(new Date(2026, 1, 15), 3);
        expect(m.map((x) => x.value)).toEqual(['2026-02', '2026-01', '2025-12']);
        expect(m[2]!.label).toBe('Tháng 12/2025');
    });
});

describe('supplier codes from an invoice', () => {
    const v = (id: string) => ({ id, sku: id, name: id, score: 1, cost_price: null });
    const result: ScanResult = {
        supplier: { read: { name: 'NCC', tax_code: '', address: '', phone: '' }, match: null },
        invoice: { number: '12', series: '1C26TAA', date: '2026-11-18' },
        lines: [
            { name: 'A', code: 'NCC-A', unit: '', quantity: 2, unit_price: 100, amount: 200, status: 'matched', variant: v('A'), candidates: [] },
            { name: 'A lần 2', code: 'NCC-A2', unit: '', quantity: 1, unit_price: 100, amount: 100, status: 'matched', variant: v('A'), candidates: [] },
            { name: 'B', code: '', unit: '', quantity: 1, unit_price: 50, amount: 50, status: 'matched', variant: v('B'), candidates: [] },
        ],
        discount: 0, shipping: 0, vat_amount: 0, vat_rate: 8, total: 350, sum: 350, total_matches: true, unreadable: '',
    };

    it('keeps the first code per SKU and skips lines without one', () => {
        expect(supplierCodes(reviewLines(result.lines))).toEqual([{ id: 'A', code: 'NCC-A' }]);
    });

    it('carries the invoice to the purchase form', () => {
        const d = buildDraft(result, reviewLines(result.lines), 's1', 'w1', 'inv1');
        expect(d.purchase_invoice_id).toBe('inv1');
        expect(d.note).toBe('Tạo từ hoá đơn điện tử.');
        expect(d.lines).toEqual([{ id: 'A', sku: 'A', name: 'A', quantity: 3, unit_cost: 100 }, { id: 'B', sku: 'B', name: 'B', quantity: 1, unit_cost: 50 }]);
        expect(buildDraft(result, reviewLines(result.lines), 's1', 'w1').purchase_invoice_id).toBeNull();
    });
});
