import { describe, expect, it } from 'vitest';
import { daysUntil, periodLabel, shiftPeriod, short, thresholdProgress } from './accounting';

describe('periods', () => {
    it('moves across years for quarters and months', () => {
        expect(shiftPeriod('2026-Q1', -1)).toBe('2025-Q4');
        expect(shiftPeriod('2026-Q4', 1)).toBe('2027-Q1');
        expect(shiftPeriod('2026-01', -1)).toBe('2025-12');
        expect(shiftPeriod('2026-11', 2)).toBe('2027-01');
    });
    it('labels them in Vietnamese', () => {
        expect(periodLabel('2026-Q4')).toBe('Quý 4/2026');
        expect(periodLabel('2026-03')).toBe('Tháng 3/2026');
    });
});

describe('thresholdProgress', () => {
    it('caps at 100% and never goes below nothing left', () => {
        expect(thresholdProgress(250, 1000)).toEqual({ pct: 25, left: 750 });
        expect(thresholdProgress(1500, 1000)).toEqual({ pct: 100, left: 0 });
        expect(thresholdProgress(10, 0)).toEqual({ pct: 100, left: 0 });
    });
});

describe('daysUntil and short', () => {
    it('counts whole days to a deadline', () => {
        expect(daysUntil('2027-01-31', new Date(2026, 10, 28, 15))).toBe(64);
        expect(daysUntil('2026-11-27', new Date(2026, 10, 28))).toBe(-1);
    });
    it('writes money briefly', () => {
        expect(short(71240000)).toBe('71,2 tr');
        expect(short(1250000000)).toBe('1,3 tỷ');
    });
});
