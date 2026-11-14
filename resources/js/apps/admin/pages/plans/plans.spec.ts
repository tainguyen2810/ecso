import { describe, expect, it } from 'vitest';
import { featurePayload, featureRows, limitSummary, moved, yearlyHint, type PlanFeature } from './plans';

const f = (type: 'limit' | 'boolean', enabled: boolean, limit: number | null = null): PlanFeature => ({ name: '', type, enabled, limit });

describe('admin plans', () => {
    it('sums up the main limits', () => {
        expect(limitSummary({ 'limit.users': f('limit', true, 2), 'limit.shops': f('limit', true, 1), 'limit.warehouses': f('limit', true, 1), 'limit.orders_per_month': f('limit', true, 300) })).toBe('2 người · 1 shop · 1 kho · 300 đơn/tháng');
        expect(limitSummary({ 'limit.users': f('limit', true), 'limit.shops': f('limit', true), 'limit.warehouses': f('limit', true), 'limit.orders_per_month': f('limit', true) })).toBe('Không giới hạn');
        expect(limitSummary({ 'limit.users': f('limit', true, 10), 'limit.shops': f('limit', false) })).toBe('10 người · 0 shop');
    });

    it('turns form rows into what the API takes', () => {
        const defs = [
            { key: 'limit.users', type: 'limit' as const, name: 'Người' },
            { key: 'limit.shops', type: 'limit' as const, name: 'Shop' },
            { key: 'reports.advanced', type: 'boolean' as const, name: 'Báo cáo' },
        ];
        const rows = featureRows(defs, { 'limit.users': f('limit', true), 'limit.shops': f('limit', true, 3) });
        expect(rows.map((r) => [r.unlimited, r.limit, r.enabled])).toEqual([
            [true, null, true],
            [false, 3, true],
            [false, null, false],
        ]);

        rows[1].limit = 0;
        rows[2].enabled = true;
        expect(featurePayload(rows)).toEqual({ 'limit.users': { enabled: true, limit: null }, 'limit.shops': { enabled: false, limit: null }, 'reports.advanced': { enabled: true } });
    });

    it('explains the yearly price and moves plans', () => {
        expect(yearlyHint(499000, 4990000)).toBe('= 415.833 ₫/tháng, tiết kiệm 17%');
        expect(yearlyHint(0, 0)).toBe('');
        expect(moved(['a', 'b', 'c'], 'b', -1)).toEqual(['b', 'a', 'c']);
        expect(moved(['a', 'b'], 'b', 1)).toEqual(['a', 'b']);
    });
});
