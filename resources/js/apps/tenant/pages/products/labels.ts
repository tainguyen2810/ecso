import { encodeBarcode } from './barcode';
import { approxMetrics, type FontMetrics } from './fontMetrics';
import { formatNumber } from '@/shared/lib/utils';

export interface LabelItem {
    sku: string;
    barcode: string | null;
    productName: string;
    variantName: string | null;
    /** Price from the chosen price list. */
    price: string | number | null;
    /** Unit of measure (ĐVT), printed after the price when asked. */
    unit?: string | null;
    quantity: number;
}

export interface LabelOptions {
    paper: PaperKey;
    showName: boolean;
    showPrice: boolean;
    /** Price followed by "VND". */
    priceCurrency: boolean;
    /** Price followed by "/unit". */
    priceUnit: boolean;
    /** Sapo's "Khổ rộng": wider barcode bars. */
    wide: boolean;
    /** Store name on top; empty = not printed. */
    storeName: string;
    /** Printer calibration, mm (can be negative). */
    offsetTop: number;
    offsetLeft: number;
}

/** pt per mm. */
export const MM = 72 / 25.4;

/**
 * How text and bars sit inside one label, in pt, measured from Sapo's own PDF (khổ 74x22, Chrome print to PDF,
 * Arial): name 6.5 pt on at most 2 lines 7.83 pt apart, first baseline 10.5 pt from the top, bars 22 pt high
 * starting 3.17 pt under the last name baseline, 0.852 pt per module, code 7 pt with its baseline 7.11 pt under the bars.
 */
export interface LabelStyle {
    nameSize: number;
    nameLines: number;
    /** Baseline to baseline. */
    linePitch: number;
    /** Label top to the first baseline. */
    firstBaseline: number;
    /** Left/right padding the text wraps inside. */
    padX: number;
    /** Last text baseline to the top of the bars. */
    barGap: number;
    barHeight: number;
    /** Narrowest bar, pt; shrunk when the code does not fit the label. */
    module: number;
    /** Module when "Khổ rộng" is off. */
    narrowModule: number;
    codeSize: number;
    /** Bottom of the bars to the code baseline. */
    codeGap: number;
    priceSize: number;
}

/** A paper, mm: the page the printer feeds (one roll row or a sheet) and the grid of labels on it. */
export interface Paper {
    group: 'roll2' | 'roll3' | 'a4' | 'a5';
    label: string;
    page: [number, number];
    columns: number;
    rows: number;
    width: number;
    height: number;
    /** Left edge to left edge / top to top. */
    pitchX: number;
    pitchY: number;
    left: number;
    top: number;
    style: LabelStyle;
    /** Geometry checked against a Sapo PDF. */
    measured: boolean;
}

const sapo22: LabelStyle = {
    nameSize: 6.5,
    nameLines: 2,
    linePitch: 7.83,
    firstBaseline: 10.5,
    padX: 2,
    barGap: 3.17,
    barHeight: 22,
    module: 0.35496 * 2.4,
    narrowModule: 0.35496 * 2,
    codeSize: 7,
    codeGap: 7.11,
    priceSize: 7,
};

/** Scales the 22 mm style to a smaller label height (A4/A5 sheets) until their Sapo PDFs are measured. */
function scaled(height: number): LabelStyle {
    const k = Math.min(1, height / 22);
    return {
        ...sapo22,
        nameSize: Math.max(4.5, sapo22.nameSize * k),
        nameLines: height >= 20 ? 2 : 1,
        linePitch: Math.max(5.4, sapo22.linePitch * k),
        firstBaseline: Math.max(6, sapo22.firstBaseline * k),
        barGap: sapo22.barGap * k,
        barHeight: sapo22.barHeight * k,
        codeSize: Math.max(4.5, sapo22.codeSize * k),
        codeGap: Math.max(4.6, sapo22.codeGap * k),
        priceSize: Math.max(4.5, sapo22.priceSize * k),
    };
}

const ROLL_PITCH = 105.16 / MM; // 37.1 mm, measured

export const papers = {
    r72: { group: 'roll2', label: 'Khổ 72x22 mm', page: [72, 22], columns: 2, rows: 1, width: 35, height: 22, pitchX: ROLL_PITCH, pitchY: 22, left: 0, top: 0, style: sapo22, measured: false },
    r74: { group: 'roll2', label: 'Khổ 74x22 mm', page: [75, 22], columns: 2, rows: 1, width: 35, height: 22, pitchX: ROLL_PITCH, pitchY: 22, left: 0, top: 0, style: sapo22, measured: true },
    r110: { group: 'roll3', label: '110 x 22 mm', page: [110, 22], columns: 3, rows: 1, width: 35, height: 22, pitchX: ROLL_PITCH, pitchY: 22, left: 0, top: 0, style: sapo22, measured: false },
    t145: { group: 'a4', label: 'Tomy No.145 - 65 tem', page: [210, 297], columns: 5, rows: 13, width: 38, height: 21, pitchX: 40, pitchY: 21, left: 6, top: 12, style: scaled(21), measured: false },
    t146: { group: 'a4', label: 'Tomy No.146 - 180 tem', page: [210, 297], columns: 10, rows: 18, width: 20, height: 15, pitchX: 20, pitchY: 15, left: 5, top: 13.5, style: scaled(15), measured: false },
    t138: { group: 'a4', label: 'Tomy No.138 - 100 tem', page: [210, 297], columns: 5, rows: 20, width: 40, height: 14, pitchX: 40, pitchY: 14, left: 5, top: 8.5, style: scaled(14), measured: false },
    t108: { group: 'a5', label: 'Tomy No.108 - 40 tem', page: [148, 210], columns: 5, rows: 8, width: 26, height: 20, pitchX: 28, pitchY: 22, left: 5, top: 15, style: scaled(20), measured: false },
} satisfies Record<string, Paper>;

export type PaperKey = keyof typeof papers;

export const paperGroups: { key: Paper['group']; label: string }[] = [
    { key: 'roll2', label: 'Khổ 2 tem' },
    { key: 'roll3', label: 'Khổ 3 tem' },
    { key: 'a4', label: 'Khổ giấy A4' },
    { key: 'a5', label: 'Khổ giấy A5' },
];

export const defaultOptions: LabelOptions = {
    paper: 'r74',
    showName: true,
    showPrice: false,
    priceCurrency: false,
    priceUnit: false,
    wide: true,
    storeName: '',
    offsetTop: 0,
    offsetLeft: 0,
};

export function isRoll(paper: Paper): boolean {
    return paper.group === 'roll2' || paper.group === 'roll3';
}

/** The code printed on the label: the barcode when the SKU has one, else the SKU itself. */
export function labelCode(item: Pick<LabelItem, 'sku' | 'barcode'>): string {
    return (item.barcode ?? '').trim() || item.sku;
}

/** Codes that print but scan badly on a small label (Sapo's warning): special characters or longer than 15. */
export function codeWarning(code: string): string | null {
    if (/[^\x20-\x7E]/.test(code)) return 'Mã có dấu tiếng Việt, không in được mã vạch.';
    if (/[@*^,./~]/.test(code)) return 'Mã có ký tự đặc biệt, máy quét có thể không đọc được.';
    if (code.length > 15) return 'Mã dài quá 15 ký tự, máy quét có thể không đọc được.';
    return null;
}

export function labelName(item: LabelItem): string {
    return [item.productName, item.variantName].filter(Boolean).join(' - ');
}

export function priceText(item: LabelItem, options: Pick<LabelOptions, 'priceCurrency' | 'priceUnit'>): string | null {
    if (item.price === null || item.price === '' || !(Number(item.price) > 0)) return null;
    let text = formatNumber(item.price);
    if (options.priceCurrency) text += ' VND';
    if (options.priceUnit && item.unit) text += `/${item.unit}`;
    return text;
}

/** One entry per printed label, in order. */
export function expandLabels(items: LabelItem[]): LabelItem[] {
    return items.flatMap((item) => Array.from({ length: Math.max(0, Math.floor(item.quantity)) }, () => item));
}

/** Greedy word wrap like the browser: whole words, a word longer than the line is cut. Extra lines are dropped (as Sapo does). */
export function wrapText(text: string, maxWidth: number, size: number, metrics: FontMetrics, maxLines: number): string[] {
    const lines: string[] = [];
    let current = '';
    for (const word of text.split(/\s+/).filter(Boolean)) {
        const candidate = current ? `${current} ${word}` : word;
        if (metrics.width(candidate, size) <= maxWidth) {
            current = candidate;
            continue;
        }
        if (current) lines.push(current);
        current = word;
        while (metrics.width(current, size) > maxWidth && current.length > 1) {
            let cut = current.length - 1;
            while (cut > 1 && metrics.width(current.slice(0, cut), size) > maxWidth) cut--;
            lines.push(current.slice(0, cut));
            current = current.slice(cut);
        }
    }
    if (current) lines.push(current);
    return lines.slice(0, maxLines);
}

/** Drawn element of a label, pt from the label's top-left. Text is centred on `cx`; `y` is its baseline. */
export type LabelPart =
    | { kind: 'text'; role: 'store' | 'name' | 'code' | 'price'; text: string; cx: number; y: number; size: number; bold: boolean }
    | { kind: 'bars'; x: number; y: number; height: number; module: number; bars: { x: number; width: number }[]; width: number };

/**
 * Lays out one label top-down like Sapo: store, name lines, bars, code, price. Pure function: the PDF and the
 * on-screen preview draw these parts, so the preview is what prints.
 */
export function labelParts(item: LabelItem, options: LabelOptions, paper: Paper, metrics: FontMetrics = approxMetrics): LabelPart[] {
    const s = paper.style;
    const width = paper.width * MM;
    const height = paper.height * MM;
    const cx = width / 2;
    const parts: LabelPart[] = [];
    let baseline = s.firstBaseline - s.linePitch;
    let hasText = false;
    const textLine = (role: 'store' | 'name', text: string, bold: boolean) => {
        baseline += s.linePitch;
        hasText = true;
        parts.push({ kind: 'text', role, text, cx, y: baseline, size: s.nameSize, bold });
    };
    if (options.storeName) textLine('store', wrapText(options.storeName, width - s.padX * 2, s.nameSize, metrics, 1)[0] ?? '', true);
    if (options.showName) for (const line of wrapText(labelName(item), width - s.padX * 2, s.nameSize, metrics, s.nameLines)) textLine('name', line, false);

    const code = labelCode(item);
    const price = options.showPrice ? priceText(item, options) : null;
    const barTop = hasText ? baseline + s.barGap : s.firstBaseline - s.nameSize * metrics.ascent;
    // Bars shrink (never the text) when store + 2 name lines + price would overflow the label.
    const below = s.codeGap + metrics.descent * s.codeSize + (price ? s.linePitch * (s.priceSize / s.nameSize) : 0) + 1;
    const barHeight = Math.max(Math.min(s.barHeight, height - barTop - below), s.barHeight * 0.4);
    const encoded = encodeBarcode(code);
    const module = Math.min(options.wide ? s.module : s.narrowModule, (width - s.padX * 2) / encoded.modules);
    const barsWidth = encoded.modules * module;
    parts.push({ kind: 'bars', x: cx - barsWidth / 2, y: barTop, height: barHeight, module, bars: encoded.bars, width: barsWidth });
    let y = barTop + barHeight + s.codeGap;
    parts.push({ kind: 'text', role: 'code', text: code, cx, y, size: s.codeSize, bold: false });
    if (price) {
        y += s.linePitch * (s.priceSize / s.nameSize);
        parts.push({ kind: 'text', role: 'price', text: price, cx, y, size: s.priceSize, bold: true });
    }
    return parts;
}

/** Where each label's top-left sits on its page, mm, calibration included. */
export function labelSlots(paper: Paper, options: Pick<LabelOptions, 'offsetTop' | 'offsetLeft'>): { x: number; y: number }[] {
    const dx = Number(options.offsetLeft) || 0;
    const dy = Number(options.offsetTop) || 0;
    const slots = [];
    for (let r = 0; r < paper.rows; r++) {
        for (let c = 0; c < paper.columns; c++) slots.push({ x: paper.left + c * paper.pitchX + dx, y: paper.top + r * paper.pitchY + dy });
    }
    return slots;
}

/** Splits the labels into pages of the paper. */
export function paginate(paper: Paper, options: LabelOptions, labels: LabelItem[]): { item: LabelItem; slot: { x: number; y: number } }[][] {
    const slots = labelSlots(paper, options);
    const pages = [];
    for (let i = 0; i < labels.length; i += slots.length) {
        pages.push(labels.slice(i, i + slots.length).map((item, j) => ({ item, slot: slots[j]! })));
    }
    return pages;
}

export const LABEL_FONT = 'LabelSans';

/** pdfmake document at the paper's exact size, every text line and bar absolutely positioned. Pure function (unit tested). */
export function labelDocument(items: LabelItem[], options: LabelOptions, metrics: FontMetrics = approxMetrics, font = LABEL_FONT): Record<string, unknown> {
    const paper: Paper = papers[options.paper];
    const content: Record<string, unknown>[] = [];
    paginate(paper, options, expandLabels(items)).forEach((page, p) => {
        if (p > 0) content.push({ text: '', pageBreak: 'before' });
        for (const { item, slot } of page) {
            const ox = slot.x * MM;
            const oy = slot.y * MM;
            for (const part of labelParts(item, options, paper, metrics)) {
                if (part.kind === 'bars') {
                    content.push({
                        absolutePosition: { x: ox + part.x, y: oy + part.y },
                        canvas: part.bars.map((b) => ({ type: 'rect', x: b.x * part.module, y: 0, w: b.width * part.module, h: part.height, color: '#000' })),
                    });
                    continue;
                }
                const w = metrics.width(part.text, part.size);
                content.push({
                    absolutePosition: { x: ox + part.cx - w / 2, y: oy + part.y - metrics.ascent * part.size },
                    text: part.text,
                    font,
                    fontSize: part.size,
                    bold: part.bold,
                    lineHeight: 1,
                    noWrap: true,
                });
            }
        }
    });

    return {
        pageSize: { width: paper.page[0] * MM, height: paper.page[1] * MM },
        pageMargins: [0, 0, 0, 0],
        content,
        defaultStyle: { font, lineHeight: 1 },
        info: { title: 'Tem ma vach' },
    };
}

let fontLoad: Promise<{ regular: ArrayBuffer; bold: ArrayBuffer }> | null = null;
/** Liberation Sans (Arial metrics, like Sapo's labels), bundled by Vite and fetched once. */
export function loadLabelFont(): Promise<{ regular: ArrayBuffer; bold: ArrayBuffer }> {
    fontLoad ??= Promise.all([import('../../../../../fonts/LiberationSans-Regular.ttf?url'), import('../../../../../fonts/LiberationSans-Bold.ttf?url')])
        .then(([r, b]) => Promise.all([fetch(r.default), fetch(b.default)]))
        .then(([r, b]) => Promise.all([r.arrayBuffer(), b.arrayBuffer()]))
        .then(([regular, bold]) => ({ regular, bold }));
    return fontLoad;
}

function base64(buffer: ArrayBuffer): string {
    let binary = '';
    const bytes = new Uint8Array(buffer);
    for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    return btoa(binary);
}

/** Opens the PDF in a new tab (to print) or downloads it. pdfmake is loaded lazily. */
export async function printLabels(items: LabelItem[], options: LabelOptions, metrics: FontMetrics, mode: 'open' | 'download' = 'open'): Promise<void> {
    const [{ default: pdfMake }, font] = await Promise.all([import('pdfmake/build/pdfmake'), loadLabelFont()]);
    pdfMake.addVirtualFileSystem({ 'LabelSans-Regular.ttf': base64(font.regular), 'LabelSans-Bold.ttf': base64(font.bold) });
    pdfMake.addFonts({ [LABEL_FONT]: { normal: 'LabelSans-Regular.ttf', bold: 'LabelSans-Bold.ttf', italics: 'LabelSans-Regular.ttf', bolditalics: 'LabelSans-Bold.ttf' } });
    const pdf = pdfMake.createPdf(labelDocument(items, options, metrics));
    if (mode === 'download') await pdf.download(`tem-ma-vach-${Date.now()}.pdf`);
    else await pdf.open();
}
