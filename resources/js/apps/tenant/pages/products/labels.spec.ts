import { describe, expect, it } from 'vitest';
import { barcodeSvg, code128Values, ean13Pattern, encodeBarcode, isEan13 } from './barcode';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { parseFontMetrics } from './fontMetrics';
import { codeWarning, defaultOptions, expandLabels, labelCode, labelDocument, labelParts, MM, papers, priceText, wrapText, type LabelItem } from './labels';

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
    type Pos = { absolutePosition: { x: number; y: number } };
    const r2 = (n: number) => Math.round(n * 100) / 100;
    const font = readFileSync(resolve(__dirname, '../../../../../fonts/LiberationSans-Regular.ttf'));
    const metrics = parseFontMetrics(font.buffer.slice(font.byteOffset, font.byteOffset + font.byteLength) as ArrayBuffer);
    // The product and settings of the Sapo sample PDF (khổ 74x22, Khổ rộng, name only).
    const sapo: LabelItem = { sku: 'ITS325', barcode: null, productName: 'Card WIFI Intel WIFI-6E AX210 khe PCI tích hợp Bluetooth 5.4 có tản nhiệt', variantName: null, price: 700000, quantity: 10 };

    it('74x22 matches the Sapo PDF: page, name lines, baselines, bars, code', () => {
        const doc = labelDocument([sapo], { ...opts, paper: 'r74' }, metrics) as Doc;
        expect(r2(doc.pageSize.width)).toBe(212.6);
        expect(r2(doc.pageSize.height)).toBe(62.36);
        // 10 labels, 2 per row: 5 pages.
        expect(doc.content.filter((c) => c.pageBreak)).toHaveLength(4);
        const texts = doc.content.filter((c) => c.text) as (Pos & { text: string; fontSize: number })[];
        expect(texts.slice(0, 3).map((t) => t.text)).toEqual(['Card WIFI Intel WIFI-6E AX210', 'khe PCI tích hợp Bluetooth 5.4', 'ITS325']);
        // Baselines from the top, as in Sapo's PDF: 10.5, 18.33, 50.61 pt.
        const baselines = texts.slice(0, 3).map((t) => r2(t.absolutePosition.y + metrics.ascent * t.fontSize));
        expect(baselines).toEqual([10.5, 18.33, 50.61]);
        // Code centred like Sapo (x 38.33 pt), second label 105.16 pt to the right.
        expect(Math.abs(texts[2]!.absolutePosition.x - 38.33)).toBeLessThan(0.1);
        expect(r2(texts[5]!.absolutePosition.x - texts[2]!.absolutePosition.x)).toBe(105.16);
        const bars = doc.content.find((c) => c.canvas) as Pos & { canvas: { w: number; h: number }[] };
        expect(Math.abs(bars.absolutePosition.x - 6.61)).toBeLessThan(0.05);
        expect(bars.absolutePosition.y).toBe(21.5);
        expect(bars.canvas[0]!.h).toBe(22);
        expect(r2(Math.min(...bars.canvas.map((b) => b.w)))).toBe(0.85);
    });

    it('wraps with the font widths and drops lines past the limit', () => {
        expect(wrapText(sapo.productName, 95.2, 6.5, metrics, 2)).toEqual(['Card WIFI Intel WIFI-6E AX210', 'khe PCI tích hợp Bluetooth 5.4']);
        expect(wrapText('ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJ', 40, 6.5, metrics, 3).length).toBeGreaterThan(1);
    });

    it('A4 Tomy 145 starts a new page after 65 labels', () => {
        const doc = labelDocument([{ ...item, quantity: 70 }], { ...opts, paper: 't145' }) as Doc;
        expect(doc.content.filter((c) => c.pageBreak)).toHaveLength(1);
        expect(doc.content.filter((c) => c.canvas)).toHaveLength(70);
        expect(r2(doc.pageSize.height / MM)).toBe(297);
    });

    it('calibration shifts every label', () => {
        const a = labelDocument([item], { ...opts, paper: 't138' }) as Doc;
        const b = labelDocument([item], { ...opts, paper: 't138', offsetTop: 1.5, offsetLeft: -1 }) as Doc;
        const pos = (d: Doc) => (d.content.find((c) => c.canvas) as Pos).absolutePosition;
        expect(r2((pos(b).x - pos(a).x) / MM)).toBe(-1);
        expect(r2((pos(b).y - pos(a).y) / MM)).toBe(1.5);
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

    it('everything stays inside the label, long codes get narrower bars', () => {
        for (const key of Object.keys(papers) as (keyof typeof papers)[]) {
            const p = papers[key];
            const parts = labelParts({ ...item, sku: 'ABCDEFGHIJKLMNO', productName: 'Tên sản phẩm rất dài '.repeat(5) }, { ...opts, paper: key, showPrice: true, storeName: 'Cửa hàng' }, p, metrics);
            const last = parts[parts.length - 1]!;
            expect(last.y).toBeLessThanOrEqual(p.height * MM);
            const bars = parts.find((x) => x.kind === 'bars')!;
            expect(bars.kind === 'bars' && bars.width).toBeLessThanOrEqual(p.width * MM);
        }
    });
});
