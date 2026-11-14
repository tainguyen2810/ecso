import { describe, expect, it } from 'vitest';
import { arrangeStartSteps, channelColors, channelLabel, startProgress, type RevenueChannel } from './dashboard';

const own = (key: string, type: string, name = key): RevenueChannel => ({ key, marketplace: 'manual', channel: { name, type }, revenue: '1' });

describe('dashboard channels', () => {
    it('colours own channels by kind and keeps marketplace brand colours', () => {
        const colors = channelColors([own('a', 'pos'), own('b', 'facebook'), own('c', 'facebook'), { key: 'shopee', marketplace: 'shopee', channel: null, revenue: '1' }]);
        expect(colors.a).toBe('var(--color-emerald-500)');
        expect(colors.b).toBe('var(--color-blue-500)');
        expect(colors.c).not.toBe(colors.b);
        expect(colors.shopee).toBe('var(--color-channel-shopee)');
    });

    it('names a slice by its channel, else by the marketplace', () => {
        expect(channelLabel(own('a', 'zalo', 'Zalo shop'), {})).toBe('Zalo shop');
        expect(channelLabel({ key: 'shopee', marketplace: 'shopee', channel: null, revenue: '1' }, { shopee: 'Shopee' })).toBe('Shopee');
    });

    it('finishes the start list once every required step is done', () => {
        const step = (done: boolean, optional = false) => ({ key: '', label: '', detail: '', done, optional, to: null, action: '' });
        expect(startProgress([step(true), step(false, true)])).toEqual({ done: 1, total: 2, finished: true });
        expect(startProgress([step(true), step(false)]).finished).toBe(false);
    });

    it('arranges the start list by where the shop sells and how many people work there', () => {
        const step = (key: string, optional = false) => ({ key, label: key, detail: '', done: false, optional, to: null, action: '' });
        const steps = [step('shop'), step('products'), step('order'), step('members'), step('marketplace', true)];
        expect(arrangeStartSteps(steps, null, null).map((s) => s.key)).toEqual(['shop', 'products', 'order', 'members', 'marketplace']);
        const seller = arrangeStartSteps(steps, ['shopee', 'pos'], '1');
        expect(seller.map((s) => s.key)).toEqual(['shop', 'products', 'marketplace', 'order']);
        expect(seller.find((s) => s.key === 'marketplace')?.optional).toBe(false);
        expect(arrangeStartSteps(steps, ['pos'], '2-5').map((s) => s.key)).toEqual(['shop', 'products', 'order', 'members', 'marketplace']);
    });
});
