import { describe, expect, it } from 'vitest';
import { orderCode, secondaryCodes } from './orderCode';

describe('orderCode', () => {
    it('shows the shop code first, the marketplace number before numbering starts', () => {
        expect(orderCode({ code: 'SON50892', external_order_id: '2411279KQ3D8XA' })).toBe('SON50892');
        expect(orderCode({ code: null, external_order_id: '2411279KQ3D8XA' })).toBe('2411279KQ3D8XA');
        expect(orderCode(null)).toBe('');
    });

    it('lists the marketplace, Woo, old and typed codes under the main one', () => {
        expect(secondaryCodes({ code: 'SON50897', external_order_id: '2411279KQ3D8XA', marketplace: 'shopee' })).toEqual([{ kind: 'marketplace', label: 'Shopee', value: '2411279KQ3D8XA' }]);
        expect(secondaryCodes({ code: 'SON50895', external_order_id: '10482', external_number: '10482', marketplace: 'woocommerce' })).toEqual([{ kind: 'woo', label: 'Woo', value: '#10482' }]);
        expect(secondaryCodes({ code: 'SON50888', external_order_id: 'SO-000045', marketplace: 'manual', reference: 'Woo #10391' })).toEqual([
            { kind: 'old', label: 'Mã cũ', value: 'SO-000045' },
            { kind: 'reference', label: 'Tham chiếu', value: 'Woo #10391' },
        ]);
        // A new order numbered by the shop: its own id is the code, nothing to repeat.
        expect(secondaryCodes({ code: 'SON50898', external_order_id: 'SON50898', marketplace: 'manual' })).toEqual([]);
        // Sapo order: keeps SON… as its code.
        expect(secondaryCodes({ code: 'SON50891', external_order_id: 'SON50891', marketplace: 'manual', imported_from: 'sapo' })).toEqual([]);
    });
});
