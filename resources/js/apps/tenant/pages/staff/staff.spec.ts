import { describe, expect, it } from 'vitest';
import { cellClass, cellText, monthLabel, ruleValue, shiftMonth, thisMonth, type DayCell } from './staff';

const cell = (over: Partial<DayCell>): DayCell => ({ status: 'work', hours: 8, late: 0, in: '08:00', out: '17:00', source: 'shift', shifts: [], note: null, located: false, ...over });
const money = (n: number) => `${n.toLocaleString('vi-VN')} đ`;

describe('staff helpers', () => {
    it('writes a day cell short', () => {
        expect(cellText(cell({ hours: 7.5 }))).toBe('7,5');
        expect(cellText(cell({ status: 'leave' }))).toBe('P');
        expect(cellText(cell({ status: 'off' }))).toBe('N');
        expect(cellText(cell({ status: 'no_out' }))).toBe('?');
        expect(cellText(undefined)).toBe('');
        expect(cellClass(cell({ status: 'late' }))).toContain('amber');
    });

    it('moves between months across years', () => {
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
        expect(monthLabel('2026-03')).toBe('Tháng 3/2026');
        expect(thisMonth(new Date(2026, 10, 21))).toBe('2026-11');
    });

    it('describes each commission rule', () => {
        expect(ruleValue({ kind: 'counter', rate: 1.5, amount: 0, threshold: 0 }, money)).toBe('1,5% doanh số');
        expect(ruleValue({ kind: 'category_unit', rate: 0, amount: 50000, threshold: 0 }, money)).toContain('/ sản phẩm');
        expect(ruleValue({ kind: 'store_bonus', rate: 0.5, amount: 0, threshold: 500000000 }, money)).toMatch(/^\+0,5% khi cửa hàng vượt/);
    });
});
