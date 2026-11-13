import { beforeEach, describe, expect, it } from 'vitest';
import { fold, matches, pushRecent, readRecent } from './search';

describe('global search helpers', () => {
    beforeEach(() => localStorage.clear());

    it('ignores Vietnamese marks and word order', () => {
        expect(fold('Tạo Đơn hàng')).toBe('tao don hang');
        expect(matches('Tạo phiếu nhập hàng', 'nhap phieu')).toBe(true);
        expect(matches('Tạo phiếu nhập hàng', 'chi')).toBe(false);
    });

    it('keeps the five latest opened results per tenant, without duplicates', () => {
        for (let i = 1; i <= 6; i++) pushRecent('t1', { kind: 'order', title: `SO-${i}`, detail: '', to: `/orders/${i}` });
        pushRecent('t1', { kind: 'order', title: 'SO-3', detail: '', to: '/orders/3' });
        expect(readRecent('t1').map((r) => r.title)).toEqual(['SO-3', 'SO-6', 'SO-5', 'SO-4', 'SO-2']);
        expect(readRecent('t2')).toEqual([]);
    });
});

describe('inbox', () => {
    it('counts rows that are new or grew since they were marked as seen', async () => {
        const { unseenCount } = await import('./inbox');
        const item = (key: string, count: number) => ({ key, count, group: 'orders' as const, tone: 'red' as const, title: '', detail: null, to: '/' });
        expect(unseenCount([item('a', 3), item('b', 2), item('c', 1)], { a: 3, b: 1 })).toBe(2);
        expect(unseenCount([item('a', 2)], { a: 3 })).toBe(0);
    });
});

describe('phone menu badge', () => {
    it('adds orders waiting to be confirmed or packed', async () => {
        const { ordersWaiting } = await import('./inbox');
        const item = (key: string, count: number) => ({ key, count, group: 'orders' as const, tone: 'blue' as const, title: '', detail: null, to: '/' });
        expect(ordersWaiting([item('orders.to_confirm', 2), item('orders.to_pack', 3), item('orders.overdue', 9)])).toBe(5);
    });
});
