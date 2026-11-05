/**
 * Advance widths read straight from a TrueType file (cmap format 4 + hmtx), so label text is wrapped
 * exactly as the PDF will draw it, without a canvas. Kerning is ignored (labels are short).
 */
export interface FontMetrics {
    /** Width of `text` at `size` pt, in pt. */
    width(text: string, size: number): number;
    /** Ascender as a fraction of the font size (baseline offset from the top of the line box). */
    ascent: number;
    descent: number;
}

export function parseFontMetrics(buffer: ArrayBuffer): FontMetrics {
    const view = new DataView(buffer);
    const tables = new Map<string, number>();
    const numTables = view.getUint16(4);
    for (let i = 0; i < numTables; i++) {
        const rec = 12 + i * 16;
        const tag = String.fromCharCode(view.getUint8(rec), view.getUint8(rec + 1), view.getUint8(rec + 2), view.getUint8(rec + 3));
        tables.set(tag, view.getUint32(rec + 8));
    }
    const table = (tag: string) => {
        const offset = tables.get(tag);
        if (offset === undefined) throw new Error(`Font thiếu bảng ${tag}.`);
        return offset;
    };

    const unitsPerEm = view.getUint16(table('head') + 18);
    const hhea = table('hhea');
    const ascender = view.getInt16(hhea + 4);
    const descender = view.getInt16(hhea + 6);
    const numberOfHMetrics = view.getUint16(hhea + 34);
    const hmtx = table('hmtx');
    const advance = (glyph: number) => view.getUint16(hmtx + Math.min(glyph, numberOfHMetrics - 1) * 4);

    // cmap: the Windows Unicode BMP subtable (format 4).
    const cmap = table('cmap');
    let sub = -1;
    for (let i = 0; i < view.getUint16(cmap + 2); i++) {
        const rec = cmap + 4 + i * 8;
        const offset = cmap + view.getUint32(rec + 4);
        if (view.getUint16(rec) === 3 && view.getUint16(rec + 2) === 1 && view.getUint16(offset) === 4) sub = offset;
    }
    if (sub < 0) throw new Error('Font không có bảng mã Unicode.');
    const segX2 = view.getUint16(sub + 6);
    const ends = sub + 14;
    const starts = ends + segX2 + 2;
    const deltas = starts + segX2;
    const rangeOffsets = deltas + segX2;

    const cache = new Map<number, number>();
    function glyphOf(code: number): number {
        for (let s = 0; s < segX2; s += 2) {
            if (code > view.getUint16(ends + s)) continue;
            const start = view.getUint16(starts + s);
            if (code < start) return 0;
            const delta = view.getInt16(deltas + s);
            const ro = view.getUint16(rangeOffsets + s);
            if (ro === 0) return (code + delta) & 0xffff;
            const g = view.getUint16(rangeOffsets + s + ro + (code - start) * 2);
            return g === 0 ? 0 : (g + delta) & 0xffff;
        }
        return 0;
    }
    function units(code: number): number {
        let w = cache.get(code);
        if (w === undefined) {
            w = advance(glyphOf(code));
            cache.set(code, w);
        }
        return w;
    }

    return {
        width(text, size) {
            let total = 0;
            for (const ch of text.normalize('NFC')) total += units(ch.codePointAt(0)!);
            return (total / unitsPerEm) * size;
        },
        ascent: ascender / unitsPerEm,
        descent: -descender / unitsPerEm,
    };
}

/** Rough metrics (Arial-like average) for tests and before the font has loaded. */
export const approxMetrics: FontMetrics = {
    width: (text, size) => text.length * size * 0.52,
    ascent: 0.905,
    descent: 0.212,
};
