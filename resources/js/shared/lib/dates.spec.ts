import { describe, expect, it } from 'vitest';
import { daysBetween, displayDate, monthGrid, parseDate, presetOf, rangeOf, weekdayOf } from './dates';

const now = new Date(2026, 10, 13); // Fri 13 Nov 2026

describe('dates', () => {
    it('shows ISO dates as dd/mm/yyyy', () => {
        expect(displayDate('2026-11-05')).toBe('05/11/2026');
        expect(displayDate('')).toBe('');
        expect(displayDate('2026-02-30')).toBe('');
    });

    it('understands how people type dates', () => {
        for (const t of ['15/11/2026', '15/11/26', '15/11', '151126', '15112026', '15-11-2026', '15.11.2026', '2026-11-15', ' 15 / 11 / 2026 ']) {
            expect(parseDate(t, now), t).toBe('2026-11-15');
        }
        expect(parseDate('5/1/2027', now)).toBe('2027-01-05');
    });

    it('refuses what is not a date', () => {
        for (const t of ['', 'abc', '31/02/2026', '32/01/2026', '15/13/2026', '1511', '15/11/202', '2026-02-30']) {
            expect(parseDate(t, now), t).toBeNull();
        }
    });

    it('builds a month grid from Monday', () => {
        const grid = monthGrid(2026, 10);
        expect(grid).toHaveLength(42);
        expect(grid[0]).toMatchObject({ iso: '2026-10-26', inMonth: false });
        expect(grid.find((d) => d.iso === '2026-11-01')).toMatchObject({ sunday: true, inMonth: true });
        expect(weekdayOf('2026-11-15')).toBe('Chủ nhật');
    });

    it('computes range presets', () => {
        expect(rangeOf('month', now)).toEqual({ from: '2026-11-01', to: '2026-11-13' });
        expect(rangeOf('last-month', now)).toEqual({ from: '2026-10-01', to: '2026-10-31' });
        expect(rangeOf('quarter', now)).toEqual({ from: '2026-10-01', to: '2026-11-13' });
        expect(rangeOf('year', now)).toEqual({ from: '2026-01-01', to: '2026-11-13' });
        expect(presetOf('2026-11-07', '2026-11-13', now)).toBe('7d');
        expect(presetOf('2026-11-02', '2026-11-13', now)).toBeNull();
        expect(daysBetween('2026-11-01', '2026-11-13')).toBe(13);
    });
});

describe('parseTime', () => {
    it('reads short ways of typing a time', async () => {
        const { parseTime } = await import('./dates');
        expect(parseTime('9')).toBe('09:00');
        expect(parseTime('930')).toBe('09:30');
        expect(parseTime('14:05')).toBe('14:05');
        expect(parseTime('9h30')).toBe('09:30');
        expect(parseTime('25:00')).toBeNull();
        expect(parseTime('abc')).toBeNull();
    });
});
