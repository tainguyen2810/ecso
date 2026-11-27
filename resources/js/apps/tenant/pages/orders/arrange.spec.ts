import { describe, expect, it } from 'vitest';
import { bannerIds, kindCounts, pickOrders, pickTotals, urgencyLine, visibleProducts, type ArrangeOrder, type ArrangeProduct, type ArrangeSummary } from './arrange';

const order = (id: string, products: Record<string, number>, shortage = false): ArrangeOrder => ({ id, external_order_id: id, status: 'READY_TO_PACK', source: 'marketplace', marketplace_shipping: true, ship_by_at: null, shortage, products });
const product = (key: string, name: string, orders: string[], quantity: number, sku: string | null = null): ArrangeProduct => ({ key, name, variant_name: null, sku, image: null, mapped: true, on_hand: 10, quantity, short: false, orders });

const summary: ArrangeSummary = {
    count: 4,
    urgent: 1,
    today: 3,
    ready: 3,
    orders: [order('a', { keo: 3 }), order('b', { card: 1, anten: 1 }), order('c', { anten: 2 }, true), order('d', { keo: 1 })],
    products: [product('keo', 'Keo tản nhiệt', ['a', 'd'], 4, 'KEO-30G'), product('anten', 'Anten 10 dBi', ['b', 'c'], 3), product('card', 'Card WiFi 6E', ['b'], 1)],
};

describe('Chuẩn bị hàng theo sản phẩm', () => {
    it('banner prepares the orders that have the goods, or all when none has', () => {
        expect(bannerIds(summary)).toEqual(['a', 'b', 'd']);
        expect(bannerIds({ ...summary, orders: summary.orders.map((o) => ({ ...o, shortage: true })) })).toEqual(['a', 'b', 'c', 'd']);
    });

    it('counts single and multi product orders', () => {
        expect(kindCounts(summary)).toEqual({ all: 4, single: 3, multi: 1 });
    });

    it('filters products by text and kind', () => {
        expect(visibleProducts(summary, 'keo-30', 'all').map((p) => p.key)).toEqual(['keo']);
        expect(visibleProducts(summary, '', 'multi').map((p) => p.key)).toEqual(['anten', 'card']);
        expect(visibleProducts(summary, '', 'single').map((p) => p.key)).toEqual(['keo', 'anten']);
    });

    it('picks orders holding a ticked product, skipping short ones when asked', () => {
        expect(pickOrders(summary, ['anten'], 'all', false).map((o) => o.id)).toEqual(['b', 'c']);
        expect(pickOrders(summary, ['anten'], 'all', true).map((o) => o.id)).toEqual(['b']);
        expect(pickOrders(summary, ['keo', 'card'], 'single', false).map((o) => o.id)).toEqual(['a', 'd']);
    });

    it('sums the units to pick for the chosen orders', () => {
        const chosen = pickOrders(summary, ['keo', 'anten'], 'all', true);
        expect(pickTotals(summary, ['keo', 'anten'], chosen)).toEqual([{ name: 'Keo tản nhiệt', quantity: 4 }, { name: 'Anten 10 dBi', quantity: 1 }]);
    });

    it('writes the urgency line', () => {
        expect(urgencyLine(summary)).toEqual(['1 đơn còn dưới 3 giờ', '2 đơn hạn hôm nay']);
        expect(urgencyLine({ ...summary, urgent: 0, today: 0 })).toEqual([]);
    });
});
