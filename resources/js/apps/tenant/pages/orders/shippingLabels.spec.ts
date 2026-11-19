import { describe, expect, it } from 'vitest';
import { PDFDocument } from 'pdf-lib';
import { fitInCell, mergeLabels } from './shippingLabels';

/** A label as a marketplace sends it: one 100×150 mm page, base64. */
async function label(): Promise<string> {
    const doc = await PDFDocument.create();
    doc.addPage([283.46, 425.2]).drawRectangle({ x: 10, y: 10, width: 100, height: 40 });
    const bytes = await doc.save();
    return btoa(String.fromCharCode(...bytes));
}

describe('marketplace labels', () => {
    it('fits a label in a cell, aspect kept and centred', () => {
        const r = fitInCell({ width: 100, height: 150 }, { x: 0, y: 0, width: 300, height: 300 });
        expect(r.height).toBe(300);
        expect(r.width).toBe(200);
        expect(r.x).toBe(50);
    });

    it('keeps one label a sheet on A6, puts 2 on A5 and 4 on A4', async () => {
        const files = await Promise.all([1, 2, 3, 4, 5].map(label));
        const pages = async (paper: 'a6' | 'a5' | 'a4') => (await PDFDocument.load(await mergeLabels(files, paper))).getPageCount();
        expect(await pages('a6')).toBe(5);
        expect(await pages('a5')).toBe(3);
        expect(await pages('a4')).toBe(2);
        const a4 = (await PDFDocument.load(await mergeLabels(files, 'a4'))).getPage(0).getSize();
        expect(Math.round(a4.width)).toBe(595);
    });
});
