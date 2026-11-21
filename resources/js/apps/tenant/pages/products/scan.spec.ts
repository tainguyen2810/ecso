import { describe, expect, it } from 'vitest';
import { combinations, productBody, rows, type ProductSuggestion } from './scan';

const base: ProductSuggestion = {
    images: [1, 2], name: 'Router WiFi 6 TP-Link Archer AX23', category: { read: 'Thiết bị mạng › Router', id: 'cat1', path: 'Thiết bị mạng › Router' },
    brand: { read: 'TP-Link', id: null }, sku: 'AX23', warranty_months: 24, weight_gram: 650, cost_price: 820000, price: 1030000,
    variants: [{ name: 'Màu', values: ['Đen', 'Trắng'] }], description: 'Router WiFi 6.', seen: '', missing: '', duplicate: null,
};

describe('Tạo sản phẩm từ ảnh', () => {
    it('makes one variant per option combination', () => {
        expect(combinations([{ name: 'Màu', values: ['Đen', 'Trắng'] }, { name: 'Size', values: ['M', 'L'] }])).toHaveLength(4);
        expect(combinations([])).toEqual([{}]);
    });

    it('builds the product body with SKUs per option and warranty in the description', () => {
        const b = productBody(base);
        expect(b.variants.map((v) => v.sku)).toEqual(['AX23-DEN', 'AX23-TRANG']);
        expect(b.variants[0]).toMatchObject({ name: 'Đen', options: { Màu: 'Đen' }, ref_price: 1030000, weight_gram: 650 });
        expect(b.description).toContain('Bảo hành: 24 tháng.');
        expect(b.category_id).toBe('cat1');
    });

    it('keeps a single SKU without options', () => {
        const b = productBody({ ...base, variants: [] });
        expect(b.variants).toEqual([{ sku: 'AX23', name: null, options: null, ref_price: 1030000, weight_gram: 650 }]);
    });

    it('does not tick a product that looks like one already in the catalogue', () => {
        const r = rows([base, { ...base, duplicate: { product_id: 'p', name: 'Router AX23', sku: 'AX23' } }]);
        expect(r.map((x) => x.include)).toEqual([true, false]);
    });
});
