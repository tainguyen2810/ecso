import type { PDFDocument, PDFFont, PDFPage } from 'pdf-lib';

/**
 * "Chữ in thêm lên phiếu sàn": text the shop chose (Cấu hình › Mẫu in) written into the blank space of the Shopee /
 * TikTok label. The marketplace's label is never moved, resized or covered: the page is rendered, the empty areas
 * inside the label are found, and the text goes only there. What does not fit is made smaller, then shortened, then
 * left out.
 */
export interface LabelExtras {
    picklist: { sku: string | null; name: string; quantity: number }[] | null;
    note: string | null;
    warehouse: string | null;
    notice: { lines: string[]; box: boolean } | null;
    size: 'sm' | 'md' | 'lg';
}

/** Which cells of the page have ink, on a grid of `cell` points, row 0 at the top. */
export interface InkGrid {
    cols: number;
    rows: number;
    cell: number;
    ink: Uint8Array;
}

/** In points, from the top-left corner of the page (y goes down). */
export interface Rect {
    x: number;
    y: number;
    width: number;
    height: number;
}

const MM = 72 / 25.4;
/** Grid cell: 1 mm. */
export const CELL = MM;
/** Empty margin kept around the marketplace's own ink, in cells. */
const PAD = 2;
const MIN_WIDTH = 30 * MM;
const MIN_HEIGHT = 6 * MM;

export const BASE_SIZE: Record<LabelExtras['size'], number> = { sm: 7, md: 8.5, lg: 10 };
const MIN_SIZE = 6;

/**
 * The largest empty rectangles inside the label (the bounding box of its ink), biggest first, kept PAD cells away
 * from anything the marketplace printed. The page outside the label (an A6 label on an A4 page) is never used.
 */
export function blankRects(grid: InkGrid, max = 3): Rect[] {
    const { cols, rows, ink } = grid;
    let top = rows, bottom = -1, left = cols, right = -1;
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            if (!ink[r * cols + c]) continue;
            top = Math.min(top, r);
            bottom = Math.max(bottom, r);
            left = Math.min(left, c);
            right = Math.max(right, c);
        }
    }
    if (bottom < 0) return [];
    // Blocked: ink grown by PAD, and everything outside the label's box.
    const blocked = new Uint8Array(cols * rows);
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            if (r < top + PAD || r > bottom - PAD || c < left + PAD || c > right - PAD) blocked[r * cols + c] = 1;
            if (!ink[r * cols + c]) continue;
            for (let dr = -PAD; dr <= PAD; dr++) {
                for (let dc = -PAD; dc <= PAD; dc++) {
                    const rr = r + dr, cc = c + dc;
                    if (rr >= 0 && rr < rows && cc >= 0 && cc < cols) blocked[rr * cols + cc] = 1;
                }
            }
        }
    }
    const found: Rect[] = [];
    for (let n = 0; n < max; n++) {
        const best = largestEmpty(blocked, cols, rows);
        if (!best) break;
        const rect = { x: best.c * grid.cell, y: best.r * grid.cell, width: best.w * grid.cell, height: best.h * grid.cell };
        if (rect.width < MIN_WIDTH || rect.height < MIN_HEIGHT) break;
        found.push(rect);
        for (let r = best.r - PAD; r < best.r + best.h + PAD; r++) {
            for (let c = best.c - PAD; c < best.c + best.w + PAD; c++) {
                if (r >= 0 && r < rows && c >= 0 && c < cols) blocked[r * cols + c] = 1;
            }
        }
    }
    return found;
}

/** Largest empty rectangle by area (histogram method), at least MIN_WIDTH × MIN_HEIGHT. */
function largestEmpty(blocked: Uint8Array, cols: number, rows: number) {
    const heights = new Array<number>(cols).fill(0);
    let best: { r: number; c: number; w: number; h: number; score: number } | null = null;
    const minW = Math.ceil(MIN_WIDTH / CELL);
    const minH = Math.ceil(MIN_HEIGHT / CELL);
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) heights[c] = blocked[r * cols + c] ? 0 : heights[c] + 1;
        const stack: number[] = [];
        for (let c = 0; c <= cols; c++) {
            const h = c === cols ? 0 : heights[c];
            while (stack.length && heights[stack[stack.length - 1]] >= h) {
                const i = stack.pop()!;
                const height = heights[i];
                const from = stack.length ? stack[stack.length - 1] + 1 : 0;
                const width = c - from;
                if (height >= minH && width >= minW) {
                    const score = width * height;
                    if (!best || score > best.score) best = { r: r - height + 1, c: from, w: width, h: height, score };
                }
            }
            stack.push(c);
        }
    }
    return best;
}

/** One line of text to draw, relative to its block. */
export interface Line {
    text: string;
    bold: boolean;
    size: number;
    /** Draw a tick box before the text (pick list). */
    check?: boolean;
}

export interface Placement {
    kind: 'notice' | 'picklist' | 'note' | 'warehouse';
    rect: Rect;
    lines: Line[];
    box: boolean;
    icon: boolean;
}

export type Measure = (text: string, size: number, bold: boolean) => number;

const lineHeight = (size: number) => size * 1.25;

/** Words wrapped to the width; a word longer than the line is cut. */
export function wrap(text: string, width: number, size: number, bold: boolean, measure: Measure): string[] {
    const out: string[] = [];
    let line = '';
    for (const word of text.split(/\s+/).filter(Boolean)) {
        const next = line ? `${line} ${word}` : word;
        if (measure(next, size, bold) <= width) {
            line = next;
            continue;
        }
        if (line) out.push(line);
        line = measure(word, size, bold) <= width ? word : ellipsis(word, width, size, bold, measure);
    }
    if (line) out.push(line);
    return out;
}

export function ellipsis(text: string, width: number, size: number, bold: boolean, measure: Measure): string {
    if (measure(text, size, bold) <= width) return text;
    let s = text;
    while (s.length > 1 && measure(`${s}…`, size, bold) > width) s = s.slice(0, -1);
    return `${s.trimEnd()}…`;
}

interface Block {
    kind: Placement['kind'];
    box: boolean;
    icon: boolean;
    /** Lines for a given text width and size; `limit` shortens a pick list to that many rows. */
    lines: (width: number, size: number, limit?: number) => Line[];
    rows?: number;
}

function blocks(extras: LabelExtras, measure: Measure): Block[] {
    const list: Block[] = [];
    if (extras.notice?.lines.length) {
        const n = extras.notice;
        list.push({
            kind: 'notice',
            box: n.box,
            icon: n.box,
            lines: (width, size) =>
                n.lines.flatMap((text, i) => {
                    const bold = i === 0;
                    const s = i === 0 ? size + 0.5 : size;
                    return wrap(text, width, s, bold, measure).map((t) => ({ text: t, bold, size: s }));
                }),
        });
    }
    if (extras.picklist?.length) {
        const items = extras.picklist;
        list.push({
            kind: 'picklist',
            box: false,
            icon: false,
            rows: items.length,
            lines: (width, size, limit = items.length) => {
                const shown = limit < items.length ? items.slice(0, Math.max(1, limit - 1)) : items;
                const lines: Line[] = shown.map((it) => ({ text: ellipsis(`${it.sku ? `${it.sku}  ` : ''}${it.name}  x${it.quantity}`, width - size * 1.3, size, false, measure), bold: false, size, check: true }));
                if (shown.length < items.length) lines.push({ text: `+${items.length - shown.length} mã khác`, bold: true, size });
                return lines;
            },
        });
    }
    if (extras.note) {
        const note = extras.note;
        list.push({
            kind: 'note',
            box: false,
            icon: false,
            lines: (width, size) => {
                const all = wrap(note, width, size, false, measure);
                const lines = all.slice(0, 3);
                if (all.length > 3) lines[2] = ellipsis(`${lines[2]} ${all.slice(3).join(' ')}`, width, size, false, measure);
                return lines.map((t) => ({ text: t, bold: false, size }));
            },
        });
    }
    if (extras.warehouse) {
        const w = extras.warehouse;
        list.push({ kind: 'warehouse', box: false, icon: false, lines: (width, size) => [{ text: ellipsis(`Kho: ${w}`, width, size, true, measure), bold: true, size }] });
    }
    return list;
}

/** Inner padding of a framed block and the room its camera icon takes on the left. */
const boxPad = (size: number) => size * 0.55;
const iconRoom = (size: number) => size * 2.6;

function textWidth(block: Block, rect: Rect, size: number) {
    return rect.width - (block.box ? boxPad(size) * 2 : 0) - (block.icon ? iconRoom(size) : 0);
}

function blockHeight(block: Block, lines: Line[], size: number) {
    return lines.reduce((h, l) => h + lineHeight(l.size), 0) + (block.box ? boxPad(size) * 2 : 0);
}

/**
 * Where each block goes: the notice first, then the pick list, the note and the warehouse, each in the first blank
 * area with room left. Tried at the chosen size, then smaller down to 6 pt; at 6 pt the pick list is shortened
 * ("+N mã khác") and what still does not fit is left out.
 */
export function plan(extras: LabelExtras, rects: Rect[], measure: Measure): Placement[] {
    const list = blocks(extras, measure);
    if (!list.length || !rects.length) return [];
    for (let size = BASE_SIZE[extras.size]; size >= MIN_SIZE; size -= 0.5) {
        const last = size - 0.5 < MIN_SIZE;
        const placed = place(list, rects, size, last);
        if (placed.length === list.length || last) return placed;
    }
    return [];
}

function place(list: Block[], rects: Rect[], size: number, squeeze: boolean): Placement[] {
    const used = rects.map(() => 0);
    const gap = size * 0.7;
    const out: Placement[] = [];
    for (const block of list) {
        let done = false;
        for (let i = 0; i < rects.length && !done; i++) {
            const rect = rects[i];
            const top = used[i] ? used[i] + gap : 0;
            const width = textWidth(block, rect, size);
            if (width < 15 * MM) continue;
            const limits = squeeze && block.rows ? Array.from({ length: block.rows }, (_, k) => block.rows! - k) : [undefined];
            for (const limit of limits) {
                const lines = block.lines(width, size, limit);
                const height = blockHeight(block, lines, size);
                if (top + height <= rect.height) {
                    out.push({ kind: block.kind, rect: { x: rect.x, y: rect.y + top, width: rect.width, height }, lines, box: block.box, icon: block.icon });
                    used[i] = top + height;
                    done = true;
                    break;
                }
            }
        }
        if (!done && !squeeze) return out;
    }
    return out;
}

/** Renders the page and marks each 1 mm cell that has any ink (anything darker than light grey). */
export async function scanInk(bytes: Uint8Array): Promise<InkGrid | null> {
    const pdfjs = await import('pdfjs-dist');
    // Bundled as a .js worker: some web servers do not send .mjs files as JavaScript.
    if (!pdfjs.GlobalWorkerOptions.workerPort) {
        const { default: PdfWorker } = await import('pdfjs-dist/build/pdf.worker.min.mjs?worker');
        pdfjs.GlobalWorkerOptions.workerPort = new PdfWorker();
    }
    // pdf.js takes the buffer over: give it a copy.
    const task = pdfjs.getDocument({ data: bytes.slice() });
    const doc = await task.promise;
    try {
        const page = await doc.getPage(1);
        if (page.rotate % 360 !== 0) return null;
        const scale = 3;
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return null;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvas, canvasContext: ctx, viewport }).promise;
        const px = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        const cellPx = CELL * scale;
        const cols = Math.floor(canvas.width / cellPx);
        const rows = Math.floor(canvas.height / cellPx);
        const ink = new Uint8Array(cols * rows);
        for (let y = 0; y < canvas.height; y++) {
            const r = Math.floor(y / cellPx);
            if (r >= rows) break;
            for (let x = 0; x < canvas.width; x++) {
                const c = Math.floor(x / cellPx);
                if (c >= cols) break;
                const i = (y * canvas.width + x) * 4;
                if (px[i] * 0.3 + px[i + 1] * 0.59 + px[i + 2] * 0.11 < 215) ink[r * cols + c] = 1;
            }
        }
        return { cols, rows, cell: CELL, ink };
    } finally {
        await task.destroy();
    }
}

/** Roboto (Vietnamese glyphs), regular and medium, embedded as subsets. */
export async function loadFonts(doc: PDFDocument): Promise<{ regular: PDFFont; bold: PDFFont }> {
    const [{ default: fontkit }, regularUrl, boldUrl] = await Promise.all([
        import('@pdf-lib/fontkit'),
        import('pdfmake/build/fonts/Roboto/Roboto-Regular.ttf?url'),
        import('pdfmake/build/fonts/Roboto/Roboto-Medium.ttf?url'),
    ]);
    doc.registerFontkit(fontkit);
    const [regular, bold] = await Promise.all([regularUrl.default, boldUrl.default].map((u) => fetch(u).then((r) => r.arrayBuffer())));
    return { regular: await doc.embedFont(regular, { subset: true }), bold: await doc.embedFont(bold, { subset: true }) };
}

/** Writes the planned blocks onto the page (black on white, thermal-printer friendly). */
export async function drawPlan(page: PDFPage, placements: Placement[], fonts: { regular: PDFFont; bold: PDFFont }): Promise<void> {
    const { rgb } = await import('pdf-lib');
    const black = rgb(0, 0, 0);
    const box = page.getCropBox();
    // Page coordinates: origin bottom-left of the crop box.
    const toY = (top: number) => box.y + box.height - top;
    for (const p of placements) {
        const size = p.lines[0]?.size ?? 8;
        const pad = p.box ? boxPad(size) : 0;
        if (p.box) page.drawRectangle({ x: box.x + p.rect.x, y: toY(p.rect.y + p.rect.height), width: p.rect.width, height: p.rect.height, borderColor: black, borderWidth: 1.2 });
        let x = box.x + p.rect.x + pad;
        if (p.icon) {
            // Video camera: body and lens, vertically centred.
            const s = size * 1.6;
            const cy = p.rect.y + p.rect.height / 2;
            page.drawRectangle({ x: x + size * 0.2, y: toY(cy + s * 0.32), width: s * 0.62, height: s * 0.64, borderColor: black, borderWidth: 1 });
            page.drawSvgPath(`M 0 0 L ${s * 0.32} ${-s * 0.2} L ${s * 0.32} ${s * 0.2} Z`, { x: x + size * 0.2 + s * 0.66, y: toY(cy), borderColor: black, borderWidth: 1 });
            x += iconRoom(size);
        }
        let y = p.rect.y + pad;
        for (const line of p.lines) {
            const lh = lineHeight(line.size);
            const baseline = toY(y + lh * 0.8);
            let tx = x;
            if (line.check) {
                const s = line.size * 0.75;
                page.drawRectangle({ x: tx, y: baseline - s * 0.05, width: s, height: s, borderColor: black, borderWidth: 0.7 });
                tx += line.size * 1.3;
            }
            page.drawText(line.text, { x: tx, y: baseline, size: line.size, font: line.bold ? fonts.bold : fonts.regular, color: black });
            y += lh;
        }
    }
}

/**
 * Adds the extras to the first page of one marketplace label PDF. The label comes back unchanged when it has no
 * blank area big enough or cannot be read.
 */
export async function stampLabel(bytes: Uint8Array, extras: LabelExtras, scan: (bytes: Uint8Array) => Promise<InkGrid | null> = scanInk): Promise<Uint8Array> {
    try {
        const grid = await scan(bytes);
        if (!grid) return bytes;
        const rects = blankRects(grid);
        const { PDFDocument } = await import('pdf-lib');
        const doc = await PDFDocument.load(bytes, { ignoreEncryption: true });
        const fonts = await loadFonts(doc);
        const measure: Measure = (text, size, bold) => (bold ? fonts.bold : fonts.regular).widthOfTextAtSize(text, size);
        const placements = plan(extras, rects, measure);
        if (!placements.length) return bytes;
        await drawPlan(doc.getPage(0), placements, fonts);
        return await doc.save();
    } catch {
        return bytes;
    }
}
