import { afterEach, describe, expect, it, vi } from 'vitest';
import { sapoGoOrdersBookmarklet } from './sapoGoOrdersExport';

/** Run the bookmark exactly as Chrome would (from its own source only), against fake Sapo answers. */
describe('Lấy đơn hàng Sapo bookmark', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.useRealTimers();
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/');
    });

    const order = (i: number) => ({
        id: i, code: `SON${i}`, status: 'completed', fulfillment_status: 'shipped', payment_status: 'paid', created_on: '2025-03-01T02:00:00Z', completed_on: '2025-03-02T02:00:00Z',
        source_id: 5, location_id: 7, assignee_id: 9, total: 250000, order_discount_amount: 10000, delivery_fee: { fee: 20000 },
        customer_data: { id: 77, code: 'CUZN01', name: 'Trần Minh Khoa', phone_number: '0901234567' },
        shipping_address: { full_name: 'Trần Minh Khoa', address1: '12 Lê Lợi', city: 'TP Hồ Chí Minh' },
        prepayments: [{ amount: 250000 }],
        order_line_items: [{ id: 1000 + i, variant_id: 3, sku: 'USB-AC600', product_name: 'USB Wifi AC600', variant_name: 'Đen', quantity: 2, price: 120000, discount_amount: 0 }],
    });

    it('reads every order and return and saves them as files of 2,000 orders', async () => {
        window.history.replaceState(null, '', '/admin/orders');
        const all = Array.from({ length: 2300 }, (_, i) => order(i + 1));
        const fetchMock = vi.fn(async (url: string) => {
            const page = Number(new URL(url, 'https://x.mysapogo.com').searchParams.get('page') ?? 1);
            const json = url.startsWith('/admin/locations') ? { locations: [{ id: 7, label: 'Kho chính' }] }
                : url.startsWith('/admin/sources') ? { sources: [{ id: 5, name: 'Facebook' }] }
                : url.startsWith('/admin/accounts') ? { accounts: [{ id: 9, full_name: 'Lan' }] }
                : url.startsWith('/admin/order_returns') ? { order_returns: [{ id: 1, code: 'SRN1', order_id: 3, order_code: 'SON3', status: 'received', line_items: [{ sku: 'USB-AC600', quantity: 1 }] }] }
                : { orders: all.slice((page - 1) * 250, page * 250), metadata: { total: all.length } };
            return { ok: true, status: 200, json: async () => json };
        });
        vi.stubGlobal('fetch', fetchMock);
        const saved: { name: string; blob: Blob }[] = [];
        let last: Blob | null = null;
        URL.createObjectURL = vi.fn((blob: Blob) => {
            last = blob;
            return 'blob:x';
        });
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            saved.push({ name: this.download, blob: last as unknown as Blob });
        });

        // eslint-disable-next-line @typescript-eslint/no-implied-eval
        new Function(decodeURIComponent(sapoGoOrdersBookmarklet().slice('javascript:'.length)))();
        await vi.waitFor(() => expect(saved).toHaveLength(2), { timeout: 5000 });

        expect(saved[0].name).toMatch(/^sapo-don-hang-.+-01\.json$/);
        expect(saved[1].name).toMatch(/^sapo-don-hang-.+-02\.json$/);
        const first = JSON.parse(await saved[0].blob.text());
        const second = JSON.parse(await saved[1].blob.text());
        expect(first.format).toBe('ezsell-sapo-go-orders');
        expect(first.orders).toHaveLength(2000);
        expect(second.orders).toHaveLength(300);
        expect(first.returns).toEqual([]);
        expect(second.returns[0]).toMatchObject({ code: 'SRN1', order_code: 'SON3', lines: [{ sku: 'USB-AC600', quantity: 1 }] });
        expect(first.orders[0]).toMatchObject({
            code: 'SON1', status: 'completed', source: 'Facebook', location: 'Kho chính', staff: 'Lan', total: 250000, discount: 10000, shipping_fee: 20000, paid: 250000,
            customer: { code: 'CUZN01', name: 'Trần Minh Khoa', phone: '0901234567' },
            lines: [{ sku: 'USB-AC600', name: 'USB Wifi AC600', variant: 'Đen', quantity: 2, price: 120000, service: false }],
        });
    });

    it('asks for the Sapo admin page elsewhere', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        // eslint-disable-next-line @typescript-eslint/no-implied-eval
        new Function(decodeURIComponent(sapoGoOrdersBookmarklet().slice('javascript:'.length)))();
        await Promise.resolve();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('trang quản trị Sapo');
    });
});
