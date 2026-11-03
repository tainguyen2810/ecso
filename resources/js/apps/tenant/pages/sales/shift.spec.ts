import { describe, expect, it } from 'vitest';
import { cashDifference, denominationTotal, differenceLabel, shiftReportHtml } from './shift';
import type { CashShift } from '@/shared/types';

describe('cash shift helpers', () => {
    it('sums a banknote tally, ignoring blanks and negatives', () => {
        expect(denominationTotal({ '200000': 2, '50000': '1', '20000': 1, '10000': '', '5000': -3 })).toBe(470000);
    });

    it('labels the drawer difference', () => {
        expect(cashDifference(470000, '480000.00')).toBe(-10000);
        expect(differenceLabel(-10000)).toContain('Thiếu');
        expect(differenceLabel(5000)).toContain('Thừa');
        expect(differenceLabel(0)).toBe('Khớp');
    });

    it('renders a handover slip with the count and the escaped note', () => {
        const shift = {
            id: 's', number: 'CA-000001', status: 'closed', cashier: { id: 'u', name: 'Lan' }, warehouse: null,
            opened_at: '2026-11-03T08:00:00+07:00', opening_cash: '500000.00', opening_note: null,
            closed_at: '2026-11-03T17:00:00+07:00', expected_cash: '480000.00', counted_cash: '470000.00', difference: '-10000.00',
            handed_over: '400000.00', kept_in_drawer: '70000.00', denominations: { '200000': 2, '50000': 1, '20000': 1 }, closing_note: '<b>thối nhầm</b>',
            totals: { orders: 2, cancelled: 0, revenue: '350000.00', by_method: { cash: '180000.00', bank_transfer: '150000.00' }, collected: '330000.00', cash_sales: '200000.00', cash_refunds: '20000.00', cash_in: '100000.00', cash_out: '300000.00', opening_cash: '500000.00', expected_cash: '480000.00' },
        } as CashShift;
        const html = shiftReportHtml(shift, 'Shop A');
        expect(html).toContain('CA-000001');
        expect(html).toContain('Thiếu 10.000');
        expect(html).toContain('&lt;b&gt;thối nhầm');
        expect(html).toContain('Chuyển khoản');
    });
});
