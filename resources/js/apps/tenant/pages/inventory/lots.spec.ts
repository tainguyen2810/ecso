import { describe, expect, it } from 'vitest';
import { addMonths, blankLot, daysLeft, effectiveExpiry, lotLabel, lotPayload, lotProblem } from './lots';

describe('lots', () => {
    it('adds months like the server (clamped to the month end)', () => {
        expect(addMonths('2026-01-31', 1)).toBe('2026-02-28');
        expect(addMonths('2026-09-05', 24)).toBe('2028-09-05');
        expect(addMonths('bad', 3)).toBe('');
    });

    it('counts days to expiry from today', () => {
        const today = new Date(2026, 10, 15);
        expect(daysLeft('2026-11-25', today)).toBe(10);
        expect(daysLeft('2026-11-10', today)).toBe(-5);
        expect(daysLeft(null, today)).toBeNull();
    });

    it('fills the expiry from manufacturing date + shelf life', () => {
        expect(effectiveExpiry({ mfg_date: '2026-09-05', expiry_date: '' }, { shelf_life_months: 24 })).toBe('2028-09-05');
        expect(effectiveExpiry({ mfg_date: '2026-09-05', expiry_date: '2027-01-01' }, { shelf_life_months: 24 })).toBe('2027-01-01');
        expect(effectiveExpiry({ mfg_date: '2026-09-05', expiry_date: '' }, {})).toBe('');
    });

    it('explains what stops a line from saving', () => {
        const lot = (lot_no: string, quantity: string, expiry_date = '2028-01-01', mfg_date = '') => ({ lot_no, quantity, expiry_date, mfg_date });
        expect(lotProblem([blankLot()], 5, {})).toBe('Nhập số lô và hạn sử dụng.');
        expect(lotProblem([lot('', '5')], 5, {})).toBe('Thiếu số lô.');
        expect(lotProblem([lot('A', '5', '')], 5, {})).toBe('Lô A: thiếu hạn sử dụng.');
        expect(lotProblem([lot('A', '5')], 5, { require_mfg_date: true })).toBe('Lô A: thiếu ngày sản xuất.');
        expect(lotProblem([lot('A', '3'), lot('B', '1')], 5, {})).toContain('(4)');
        expect(lotProblem([lot('A', '3'), lot('B', '2')], 5, {})).toBeNull();
    });

    it('sends only lots with a quantity', () => {
        expect(lotPayload([{ lot_no: ' A ', mfg_date: '', expiry_date: '2028-01-01', quantity: '3' }, blankLot()])).toEqual([{ lot_no: 'A', mfg_date: null, expiry_date: '2028-01-01', quantity: 3 }]);
        expect(lotLabel('')).toBe('Chưa rõ lô');
    });
});
