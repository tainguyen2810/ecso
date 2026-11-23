import { afterEach, describe, expect, it, vi } from 'vitest';
import { sapoGoVouchersBookmarklet } from './sapoGoVouchersExport';

/** Run the bookmark exactly as Chrome would (from its own source only), against fake Sapo answers. */
describe('Lấy sổ quỹ Sapo bookmark', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/');
    });

    it('keeps the vouchers the shop wrote and leaves out those Sapo made for orders', async () => {
        window.history.replaceState(null, '', '/admin/payment_vouchers');
        const fetchMock = vi.fn(async (url: string) => {
            const json = url.startsWith('/admin/receipt_vouchers')
                ? { receipt_vouchers: [
                    { id: 1, code: 'RVN001', type: 'Tự động', ref_code: 'SON00012', amount: 500000 },
                    { id: 2, code: 'RVN002', receipt_voucher_type: { name: 'Thu nhập khác' }, object_type: 'other', object_name: 'Bán phế liệu', amount: 200000, payment_method_name: 'Tiền mặt', issued_on: '2025-04-03T03:00:00Z' },
                ] }
                : { payment_vouchers: [
                    { id: 3, code: 'PVN001', payment_voucher_type: { name: 'PHÍ VẬN CHUYỂN' }, object_type: 'delivery_service', object_name: 'GHTK', amount: 34000, accounting: true, note: 'Ship hàng' },
                    { id: 4, code: 'PVN002', payment_voucher_type: { name: 'Thanh toán đơn nhập' }, ref_code: 'PON00007', amount: 1000000 },
                ] };
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
        new Function(decodeURIComponent(sapoGoVouchersBookmarklet().slice('javascript:'.length)))();
        await vi.waitFor(() => expect(saved).toHaveLength(1), { timeout: 5000 });

        expect(saved[0].name).toMatch(/^sapo-so-quy-.+\.json$/);
        const file = JSON.parse(await saved[0].blob.text());
        expect(file.format).toBe('ezsell-sapo-go-vouchers');
        expect(file.skipped_auto).toBe(2);
        expect(file.vouchers).toHaveLength(2);
        expect(file.vouchers[0]).toMatchObject({ code: 'RVN002', direction: 'receipt', type: 'Thu nhập khác', party_name: 'Bán phế liệu', amount: 200000, method: 'Tiền mặt', paid_on: '2025-04-03T03:00:00Z' });
        expect(file.vouchers[1]).toMatchObject({ code: 'PVN001', direction: 'payment', type: 'PHÍ VẬN CHUYỂN', party_kind: 'delivery_service', amount: 34000, counts_in_pnl: true, note: 'Ship hàng' });
    });

    it('asks for the Sapo admin page elsewhere', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        // eslint-disable-next-line @typescript-eslint/no-implied-eval
        new Function(decodeURIComponent(sapoGoVouchersBookmarklet().slice('javascript:'.length)))();
        await Promise.resolve();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('trang quản trị Sapo');
    });
});
