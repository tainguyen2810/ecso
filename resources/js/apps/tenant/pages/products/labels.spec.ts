import { describe, expect, it } from 'vitest';
import { barcodeSvg, code128Values, ean13Pattern, encodeBarcode, isEan13 } from './barcode';
import { codeWarning, defaultOptions, expandLabels, labelBoxes, labelCode, labelDocument, MM, papers, priceText, type LabelItem } from './labels';

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

    const opts = { ...defaultOptions };
    type Doc = { pageSize: { width: number; height: number }; content: Record<string, unknown>[] };
    const mm = (pt: unknown) => Math.round((Number(pt) / MM) * 10) / 10;

    it('2-label roll: page is the roll row (74x22), labels at 1 mm and 38 mm', () => {
        const doc = labelDocument([item], { ...opts, paper: 'r74' }) as Doc;
        expect(mm(doc.pageSize.width)).toBe(74);
        expect(mm(doc.pageSize.height)).toBe(22);
        // 3 labels = 2 rows: one page break.
        expect(doc.content.filter((c) => c.pageBreak)).toHaveLength(1);
        const bars = doc.content.filter((c) => c.svg) as { absolutePosition: { x: number } }[];
        expect(bars.map((b) => mm(b.absolutePosition.x))).toEqual([2, 39, 2]);
        expect(JSON.stringify(doc.content)).toContain('159.000');
    });

    it('wide roll prints one label across the row', () => {
        const doc = labelDocument([item], { ...opts, paper: 'r72', wide: true }) as Doc;
        expect(doc.content.filter((c) => c.pageBreak)).toHaveLength(2);
        const bar = doc.content.find((c) => c.svg) as { width: number };
        expect(mm(bar.width)).toBe(70);
    });

    it('A4 Tomy 145 starts a new page after 65 labels', () => {
        const doc = labelDocument([{ ...item, quantity: 70 }], { ...opts, paper: 't145', showName: false, showPrice: false }) as Doc;
        expect(doc.content.filter((c) => c.pageBreak)).toHaveLength(1);
        expect(doc.content.filter((c) => c.svg)).toHaveLength(70);
        expect(mm(doc.pageSize.height)).toBe(297);
    });

    it('calibration shifts every label', () => {
        const a = labelDocument([item], { ...opts, paper: 't138' }) as Doc;
        const b = labelDocument([item], { ...opts, paper: 't138', offsetTop: 1.5, offsetLeft: -1 }) as Doc;
        const pos = (d: Doc) => (d.content.find((c) => c.svg) as { absolutePosition: { x: number; y: number } }).absolutePosition;
        expect(mm(pos(b).x - pos(a).x)).toBe(-1);
        expect(mm(pos(b).y - pos(a).y)).toBe(1.5);
    });

    it('price with currency and unit', () => {
        expect(priceText({ ...item, unit: 'Cái' }, { priceCurrency: true, priceUnit: true })).toBe('159.000 VND/Cái');
        expect(priceText({ ...item, price: 0 }, { priceCurrency: false, priceUnit: false })).toBeNull();
    });

    it('warns about codes that scan badly', () => {
        expect(codeWarning('79P-05929')).toBeNull();
        expect(codeWarning('A/B')).not.toBeNull();
        expect(codeWarning('ABCDEFGHIJKLMNOP')).not.toBeNull();
    });

    it('label boxes stay inside the label', () => {
        for (const key of Object.keys(papers) as (keyof typeof papers)[]) {
            const p = papers[key];
            const boxes = labelBoxes({ ...item, productName: 'Tên sản phẩm rất dài '.repeat(5) }, { ...opts, storeName: 'Cửa hàng' }, p.width, p.height);
            const last = boxes[boxes.length - 1]!;
            expect(last.y + last.height).toBeLessThanOrEqual(p.height + 0.01);
        }
    });
});
