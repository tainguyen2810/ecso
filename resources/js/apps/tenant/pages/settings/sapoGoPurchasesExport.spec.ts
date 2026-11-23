import { afterEach, describe, expect, it, vi } from 'vitest';
import { sapoGoPurchasesBookmarklet } from './sapoGoPurchasesExport';

/** Run the bookmark exactly as Chrome would (from its own source only), against fake Sapo answers. */
describe('Lấy nhập hàng Sapo bookmark', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/');
    });

    it('reads suppliers, purchase orders and returns and saves one file', async () => {
        window.history.replaceState(null, '', '/admin/purchase_orders');
        const fetchMock = vi.fn(async (url: string) => {
            const json = url.startsWith('/admin/locations') ? { locations: [{ id: 7, label: 'Kho chính' }] }
                : url.startsWith('/admin/accounts') ? { accounts: [{ id: 9, full_name: 'Lan' }] }
                : url.startsWith('/admin/suppliers') ? { suppliers: [{ id: 3, code: 'SUPN00001', name: 'Công ty Sao Mai', phone_number: '0281234567', debt: 600000 }] }
                : url.startsWith('/admin/purchase_orders/2.json') ? { purchase_order: { id: 2, code: 'PON002', status: 'active', supplier_id: 3, line_items: [{ sku: 'USB-AC600', quantity: 5, price: 90000 }] } }
                : url.startsWith('/admin/purchase_orders') ? { purchase_orders: [
                    { id: 1, code: 'PON001', status: 'completed', receive_status: 'received', supplier_id: 3, location_id: 7, assignee_id: 9, total: 1000000,
                        prepayments: [{ amount: 1000000, payment_method_name: 'Tiền mặt', paid_on: '2025-04-02T03:00:00Z' }],
                        line_items: [{ sku: 'USB-AC600', product_name: 'USB Wifi AC600', quantity: 10, received_quantity: 10, price: 100000 }] },
                    { id: 2, code: 'PON002', status: 'active', supplier_id: 3 },
                ] }
                : { supplier_returns: [{ id: 5, code: 'PRN01', purchase_order_id: 1, supplier_id: 3, status: 'completed', total_amount: 200000, line_items: [{ sku: 'USB-AC600', quantity: 2, price: 100000 }] }] };
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
        new Function(decodeURIComponent(sapoGoPurchasesBookmarklet().slice('javascript:'.length)))();
        await vi.waitFor(() => expect(saved).toHaveLength(1), { timeout: 5000 });

        expect(saved[0].name).toMatch(/^sapo-nhap-hang-.+\.json$/);
        const file = JSON.parse(await saved[0].blob.text());
        expect(file.format).toBe('ezsell-sapo-go-purchases');
        expect(file.suppliers[0]).toMatchObject({ code: 'SUPN00001', name: 'Công ty Sao Mai', phone: '0281234567' });
        expect(file.purchases[0]).toMatchObject({
            code: 'PON001', receive_status: 'received', location: 'Kho chính', staff: 'Lan', total: 1000000,
            supplier: { code: 'SUPN00001' }, payments: [{ amount: 1000000, method: 'Tiền mặt' }],
            lines: [{ sku: 'USB-AC600', quantity: 10, received: 10, price: 100000 }],
        });
        // The list row without products was read alone.
        expect(file.purchases[1].lines).toHaveLength(1);
        expect(file.returns[0]).toMatchObject({ code: 'PRN01', purchase_code: 'PON001', total: 200000 });
    });

    it('asks for the Sapo admin page elsewhere', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        // eslint-disable-next-line @typescript-eslint/no-implied-eval
        new Function(decodeURIComponent(sapoGoPurchasesBookmarklet().slice('javascript:'.length)))();
        await Promise.resolve();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('trang quản trị Sapo');
    });
});
