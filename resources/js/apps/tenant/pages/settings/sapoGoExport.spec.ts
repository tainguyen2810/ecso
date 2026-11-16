import { afterEach, describe, expect, it, vi } from 'vitest';
import { sapoGoBookmarklet } from './sapoGoExport';

/** The bookmark runs on a Sapo page, from its own source only: run it exactly as Chrome would, against fake Sapo answers. */
describe('Lấy dữ liệu Sapo bookmark', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/');
    });

    it('reads every page of products with stock per branch and saves one file', async () => {
        window.history.replaceState(null, '', '/admin/products');
        const page = (n: number) => Array.from({ length: n }, (_, i) => ({
            id: i + 1, name: `SP ${i + 1}`, status: 'active', product_type: 'normal', images: [{ full_path: 'https://sapo.dktcdn.net/a.jpg' }],
            variants: [{ sku: `SKU-${i + 1}`, opt1: 'Mặc định', unit: 'Cái', variant_prices: [{ name: 'Giá bán lẻ', value: 1000 }], variant_import_price: 500,
                inventories: [{ location_id: 7, on_hand: 3, mac: 450.5 }], composite_items: [] }],
        }));
        const fetchMock = vi.fn(async (url: string) => ({
            ok: true,
            json: async () => url.startsWith('/admin/locations')
                ? { locations: [{ id: 7, label: 'Chi nhánh mặc định' }] }
                : { products: url.includes('page=1') ? page(250) : page(3), metadata: { total: 253 } },
        }));
        vi.stubGlobal('fetch', fetchMock);
        let saved: Blob | null = null;
        URL.createObjectURL = vi.fn((blob: Blob) => {
            saved = blob;
            return 'blob:x';
        });
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

        const href = sapoGoBookmarklet();
        expect(href.startsWith('javascript:')).toBe(true);
        // eslint-disable-next-line @typescript-eslint/no-implied-eval
        new Function(decodeURIComponent(href.slice('javascript:'.length)))();
        await vi.waitFor(() => expect(saved).not.toBeNull());

        const file = JSON.parse(await (saved as unknown as Blob).text());
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(file.format).toBe('ezsell-sapo-go');
        expect(file.locations).toEqual([{ id: 7, name: 'Chi nhánh mặc định' }]);
        expect(file.products).toHaveLength(253);
        expect(file.products[0].images).toEqual(['https://sapo.dktcdn.net/a.jpg']);
        expect(file.products[0].variants[0]).toMatchObject({ sku: 'SKU-1', retail: 1000, import: 500, stock: [{ location_id: 7, on_hand: 3, mac: 450.5 }] });
    });

    it('asks for the Sapo admin page elsewhere', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        // eslint-disable-next-line @typescript-eslint/no-implied-eval
        new Function(decodeURIComponent(sapoGoBookmarklet().slice('javascript:'.length)))();
        await Promise.resolve();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('trang quản trị Sapo');
    });
});
