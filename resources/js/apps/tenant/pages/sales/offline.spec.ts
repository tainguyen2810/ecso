import { describe, expect, it } from 'vitest';
import { findByCode, isNetworkError, offlineNumber, pruneSent, searchCatalog, takeStock, type CatalogItem, type OfflineSale } from './offline';

const item = (sku: string, name: string, extra: Partial<CatalogItem> = {}): CatalogItem => ({
    id: `id-${sku}`, sku, barcode: null, name: null, ref_price: '100000', wholesale_price: null,
    product: { id: `p-${sku}`, name, image: null }, stock: { available: 5 }, ...extra,
});
const items = [item('AO-TRANG-M', 'Áo thun cổ tròn', { name: 'Trắng · M', barcode: '8930001' }), item('QJ-29', 'Quần jean slim'), item('DEP-40', 'Dép đi trong nhà')];
const sale = (number: string, status: OfflineSale['status'], created_at = new Date().toISOString()): OfflineSale => ({
    key: number, number, created_at, status, summary: { items: 1, total: 1, customer: null, method: 'cash' }, payload: {},
});

describe('Bán khi mất mạng', () => {
    it('searches the kept catalog like the server: words without accents, barcode exactly', () => {
        expect(searchCatalog(items, 'ao thun trang').map((v) => v.sku)).toEqual(['AO-TRANG-M']);
        expect(searchCatalog(items, 'dep').map((v) => v.sku)).toEqual(['DEP-40']);
        expect(searchCatalog(items, '8930001').map((v) => v.sku)).toEqual(['AO-TRANG-M']);
        expect(searchCatalog(items, '').length).toBe(3);
        expect(searchCatalog(items, '').at(0)?.stock?.available).toBe(5);
    });

    it('finds a scanned code and takes sold units off the shown stock', () => {
        expect(findByCode(items, 'qj-29')?.id).toBe('id-QJ-29');
        expect(findByCode(items, '8930001')?.sku).toBe('AO-TRANG-M');
        expect(findByCode(items, 'QJ')).toBeUndefined();
        const copy = structuredClone(items);
        takeStock(copy, [{ variant_id: 'id-QJ-29', quantity: 2 }]);
        expect(copy[1]!.stock.available).toBe(3);
    });

    it('numbers offline receipts per day and drops sent ones after a day', () => {
        const day = new Date(2026, 10, 21, 9);
        expect(offlineNumber([], day)).toBe('OFF-2111-001');
        expect(offlineNumber([sale('OFF-2111-001', 'pending'), sale('OFF-2011-004', 'sent')], day)).toBe('OFF-2111-002');
        const old = new Date(Date.now() - 2 * 86400000).toISOString();
        expect(pruneSent([sale('A', 'sent', old), sale('B', 'failed', old), sale('C', 'sent')]).map((s) => s.number)).toEqual(['B', 'C']);
    });

    it('tells a lost connection from a refused sale', () => {
        expect(isNetworkError({ status: 0 })).toBe(true);
        expect(isNetworkError({ status: 422 })).toBe(false);
        expect(isNetworkError(null)).toBe(false);
    });
});
