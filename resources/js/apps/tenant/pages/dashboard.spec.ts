import { describe, expect, it } from 'vitest';
import { arrangeStartSteps, channelColors, channelLabel, sellerStartSteps, startProgress, type RevenueChannel } from './dashboard';

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

describe('seller start steps', () => {
    const base = { marketplaceNames: { tiktok: 'TikTok Shop' }, can: () => true, onHand: 0, recentStatuses: [] as string[] };
    it('starts with connecting a shop', () => {
        const s = sellerStartSteps({ ...base, accounts: [] });
        expect(s.map((x) => x.key)).toEqual(['shop', 'link', 'stock', 'order']);
        expect(s.every((x) => !x.done)).toBe(true);
        expect(s[2]!.to).toBe('/inventory/stocktake/new');
    });
    it('ticks each step from what the shop has', () => {
        const acc = { id: 'A1', shop_name: 'Shop Mẫu', marketplace: 'tiktok', status: 'connected', stats: { listings: 50, mapped: 8 } };
        let s = sellerStartSteps({ ...base, accounts: [acc] });
        expect(s[0]).toMatchObject({ done: true, detail: 'Đã kết nối TikTok Shop "Shop Mẫu"' });
        expect(s[1]).toMatchObject({ done: false, detail: '42 mã trên sàn chưa liên kết với sản phẩm trong kho', to: '/marketplaces/A1/listings' });
        expect(s[2]!.to).toBe('/marketplaces/A1/setup');
        s = sellerStartSteps({ ...base, accounts: [{ ...acc, stats: { listings: 50, mapped: 50 } }], onHand: 10, recentStatuses: ['READY_TO_PACK', 'HANDED_OVER'] });
        expect(startProgress(s)).toMatchObject({ done: 4, finished: true });
    });
});
