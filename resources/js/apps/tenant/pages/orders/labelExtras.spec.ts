import { describe, expect, it } from 'vitest';
import { PDFDocument } from 'pdf-lib';
import { blankRects, CELL, drawPlan, plan, stampLabel, wrap, type InkGrid, type LabelExtras } from './labelExtras';

/** A grid of `cols`×`rows` 1 mm cells; `fill` marks ink. */
function grid(cols: number, rows: number, fill: (r: number, c: number) => boolean): InkGrid {
    const ink = new Uint8Array(cols * rows);
    for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) if (fill(r, c)) ink[r * cols + c] = 1;
    return { cols, rows, cell: CELL, ink };
}

/** Each character 0.5 em wide. */
const measure = (text: string, size: number) => text.length * size * 0.5;

const extras = (over: Partial<LabelExtras> = {}): LabelExtras => ({ picklist: null, note: null, warehouse: null, notice: null, size: 'md', ...over });

describe('blank space on a marketplace label', () => {
    // A 100×150 mm label: border, text lines above 60 mm, a full-width line at 60 mm, text in rows 62-70, a QR block on the right below.
    const label = grid(100, 150, (r, c) => r === 0 || r === 149 || c === 0 || c === 99 || r === 60 || (r < 60 && r % 3 === 0) || (r >= 62 && r <= 70 && c < 60) || (r >= 62 && r <= 100 && c >= 75) || r === 120);

    it('finds the empty area under the items, away from the ink', () => {
        const [first] = blankRects(label);
        expect(first).toBeDefined();
        // Between the text (row 70) and the line at row 120, left of the QR (col 75).
        expect(first.y / CELL).toBeGreaterThanOrEqual(71);
        expect((first.y + first.height) / CELL).toBeLessThanOrEqual(120);
        expect((first.x + first.width) / CELL).toBeLessThanOrEqual(75);
        // No ink inside.
        for (let r = Math.round(first.y / CELL); r < Math.round((first.y + first.height) / CELL); r++)
            for (let c = Math.round(first.x / CELL); c < Math.round((first.x + first.width) / CELL); c++) expect(label.ink[r * 100 + c]).toBe(0);
    });

    it('never uses the page outside the label (an A6 label on an A4 page)', () => {
        const a4 = grid(210, 297, (r, c) => (r <= 150 && (c === 0 || c === 100)) || ((r === 0 || r === 150) && c <= 100) || (r === 60 && c <= 100));
        for (const rect of blankRects(a4)) {
            expect((rect.x + rect.width) / CELL).toBeLessThanOrEqual(100);
            expect((rect.y + rect.height) / CELL).toBeLessThanOrEqual(150);
        }
    });

    it('gives nothing for a page without ink', () => {
        expect(blankRects(grid(50, 50, () => false))).toEqual([]);
    });
});

describe('placing the text', () => {
    const rects = [{ x: 10, y: 200, width: 180, height: 120 }];

    it('puts the notice first, then the pick list, note and warehouse', () => {
        const placed = plan(
            extras({ notice: { lines: ['QUAY VIDEO KHI KHUI HÀNG'], box: true }, picklist: [{ sku: 'A1', name: 'Áo', quantity: 2 }], note: 'Gói kỹ', warehouse: 'Kho chính' }),
            rects,
            measure,
        );
        expect(placed.map((p) => p.kind)).toEqual(['notice', 'picklist', 'note', 'warehouse']);
        expect(placed[0].box).toBe(true);
        expect(placed[0].lines[0].bold).toBe(true);
        // Stacked, inside the blank area.
        for (let i = 1; i < placed.length; i++) expect(placed[i].rect.y).toBeGreaterThan(placed[i - 1].rect.y + placed[i - 1].rect.height - 0.01);
        expect(placed.at(-1)!.rect.y + placed.at(-1)!.rect.height).toBeLessThanOrEqual(320);
    });

    it('makes the text smaller, then shortens a long pick list, when space is short', () => {
        const items = Array.from({ length: 20 }, (_, i) => ({ sku: `SKU-${i}`, name: 'Sản phẩm', quantity: 1 }));
        const placed = plan(extras({ picklist: items }), [{ x: 0, y: 0, width: 180, height: 60 }], measure);
        expect(placed).toHaveLength(1);
        expect(placed[0].lines[0].size).toBe(6);
        expect(placed[0].lines.at(-1)!.text).toMatch(/^\+\d+ mã khác$/);
        expect(placed[0].rect.height).toBeLessThanOrEqual(60);
    });

    it('prints nothing when there is no blank area', () => {
        expect(plan(extras({ note: 'Gói kỹ' }), [], measure)).toEqual([]);
    });

    it('wraps words to the width', () => {
        expect(wrap('một hai ba bốn năm', 30, 8, false, measure)).toEqual(['một hai', 'ba bốn', 'năm']);
    });
});

describe('stamping the label', () => {
    it('leaves the label as it was when nothing fits', async () => {
        const doc = await PDFDocument.create();
        doc.addPage([283, 425]);
        const bytes = await doc.save();
        const out = await stampLabel(bytes, extras({ note: 'Gói kỹ' }), async () => grid(100, 150, () => true));
        expect(out).toBe(bytes);
    });

    it('draws on the page without changing its size', async () => {
        const doc = await PDFDocument.create();
        const page = doc.addPage([283, 425]);
        const { StandardFonts } = await import('pdf-lib');
        const font = await doc.embedFont(StandardFonts.Helvetica);
        const before = page.getSize();
        await drawPlan(page, plan(extras({ note: 'Goi ky', notice: { lines: ['QUAY VIDEO'], box: true } }), [{ x: 10, y: 200, width: 200, height: 120 }], measure), { regular: font, bold: font });
        expect(page.getSize()).toEqual(before);
        expect((await doc.save()).length).toBeGreaterThan(0);
    });
});
