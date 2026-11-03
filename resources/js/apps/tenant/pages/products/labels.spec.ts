import { describe, expect, it } from 'vitest';
import { barcodeSvg, code128Values, ean13Pattern, encodeBarcode, isEan13 } from './barcode';
import { expandLabels, labelCode, labelDocument, type LabelItem } from './labels';

describe('barcode', () => {
    it('checks the EAN-13 check digit', () => {
        expect(isEan13('8934563138165')).toBe(true);
        expect(isEan13('8934563138166')).toBe(false);
        expect(isEan13('893456313816')).toBe(false);
    });

    it('encodes EAN-13 in 95 modules with guard bars', () => {
        const pattern = ean13Pattern('4006381333931');
        expect(pattern).toHaveLength(95);
        expect(pattern.startsWith('101')).toBe(true);
        expect(pattern.slice(45, 50)).toBe('01010');
        expect(pattern.endsWith('101')).toBe(true);
        expect(encodeBarcode('4006381333931').type).toBe('ean13');
    });

    it('uses Code 128 set B for text and set C for digit runs, with the mod 103 check', () => {
        expect(code128Values('AB')).toEqual([104, 33, 34, (104 + 33 + 34 * 2) % 103, 106]);
        // 12345678 is all digits: start C, pairs 12 34 56 78.
        expect(code128Values('12345678').slice(0, 5)).toEqual([105, 12, 34, 56, 78]);
        // SKU with a long number: B for the letters, switch to C for the digits.
        expect(code128Values('SP-0001234').slice(0, 4)).toEqual([104, 51, 48, 13]);
        expect(code128Values('SP-0001234')).toContain(99);
        // A 13-digit code with a wrong check digit is not EAN-13: falls back to Code 128.
        expect(encodeBarcode('8930000000011').type).toBe('code128');
    });

    it('rejects characters a scanner cannot read back', () => {
        expect(() => code128Values('Áo')).toThrow();
    });

    it('Code 128 symbols are 11 modules each, stop is 13', () => {
        const { modules } = encodeBarcode('ATC-TRANG-M');
        // start + 11 data + check = 13 symbols × 11 + stop 13
        expect(modules).toBe(13 * 11 + 13);
        expect(barcodeSvg('ATC-TRANG-M')).toContain('<svg');
    });
});

describe('labels', () => {
    const item: LabelItem = { sku: 'ATC-TRANG-M', barcode: null, productName: 'Áo thun', variantName: 'Trắng / M', price: '159000', quantity: 3 };

    it('prints the barcode when there is one, else the SKU', () => {
        expect(labelCode(item)).toBe('ATC-TRANG-M');
        expect(labelCode({ ...item, barcode: ' 8934563138165 ' })).toBe('8934563138165');
    });

    it('repeats each SKU by its quantity', () => {
        expect(expandLabels([item, { ...item, sku: 'B', quantity: 0 }])).toHaveLength(3);
    });

    it('roll layout puts one label per page at the label size', () => {
        const doc = labelDocument([item], { layout: '50x30', showName: true, showPrice: true, heading: '' }) as { pageSize: { width: number; height: number }; content: unknown[] };
        expect(doc.content).toHaveLength(3);
        expect(Math.round(doc.pageSize.width)).toBe(142);
        expect(JSON.stringify(doc.content[0])).toContain('159.000');
    });

    it('A4 sheet layout fills 5 columns and starts a new page after 65 labels', () => {
        const doc = labelDocument([{ ...item, quantity: 70 }], { layout: 'a4-65', showName: false, showPrice: false, heading: 'Shop' }) as { content: { table: { body: unknown[][] } }[] };
        expect(doc.content).toHaveLength(2);
        expect(doc.content[0]!.table.body).toHaveLength(13);
        expect(doc.content[1]!.table.body).toHaveLength(1);
        expect(doc.content[1]!.table.body[0]).toHaveLength(5);
    });
});
