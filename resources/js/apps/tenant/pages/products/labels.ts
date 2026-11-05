import { barcodeSvg } from './barcode';
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
    /** Roll paper: one label across the whole row instead of 2 or 3. */
    wide: boolean;
    /** Store name on top; empty = not printed. */
    storeName: string;
    /** Printer calibration, mm (can be negative). */
    offsetTop: number;
    offsetLeft: number;
}

/**
 * A paper: the page the printer feeds (a roll row or a sheet) and the grid of labels on it, all in mm.
 * Roll rows are one PDF page each, the size the label printer driver is set to.
 */
export interface Paper {
    group: 'roll2' | 'roll3' | 'a4' | 'a5';
    label: string;
    page: [number, number];
    columns: number;
    rows: number;
    /** One label, mm. */
    width: number;
    height: number;
    /** Gap between columns / rows. */
    gapX: number;
    gapY: number;
    /** Page margin to the first label. */
    left: number;
    top: number;
}

export const papers = {
    r72: { group: 'roll2', label: 'Khổ 72x22 mm', page: [72, 22], columns: 2, rows: 1, width: 35, height: 22, gapX: 2, gapY: 0, left: 0, top: 0 },
    r74: { group: 'roll2', label: 'Khổ 74x22 mm', page: [74, 22], columns: 2, rows: 1, width: 35, height: 22, gapX: 2, gapY: 0, left: 1, top: 0 },
    r110: { group: 'roll3', label: '110 x 22 mm', page: [110, 22], columns: 3, rows: 1, width: 35, height: 22, gapX: 2, gapY: 0, left: 2.5, top: 0 },
    t145: { group: 'a4', label: 'Tomy No.145 - 65 tem', page: [210, 297], columns: 5, rows: 13, width: 38, height: 21, gapX: 2, gapY: 0, left: 6, top: 12 },
    t146: { group: 'a4', label: 'Tomy No.146 - 180 tem', page: [210, 297], columns: 10, rows: 18, width: 20, height: 15, gapX: 0, gapY: 0, left: 5, top: 13.5 },
    t138: { group: 'a4', label: 'Tomy No.138 - 100 tem', page: [210, 297], columns: 5, rows: 20, width: 40, height: 14, gapX: 0, gapY: 0, left: 5, top: 8.5 },
    t108: { group: 'a5', label: 'Tomy No.108 - 40 tem', page: [148, 210], columns: 5, rows: 8, width: 26, height: 20, gapX: 2, gapY: 2, left: 5, top: 15 },
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
    showPrice: true,
    priceCurrency: false,
    priceUnit: false,
    wide: false,
    storeName: '',
    offsetTop: 0,
    offsetLeft: 0,
};

export function isRoll(paper: Paper): boolean {
    return paper.group === 'roll2' || paper.group === 'roll3';
}

/** pt per mm. */
export const MM = 72 / 25.4;

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

/** A positioned box inside a label, mm from the label's top-left. Shared by the PDF and the on-screen preview. */
export interface LabelBox {
    kind: 'store' | 'name' | 'barcode' | 'code' | 'price';
    text: string;
    x: number;
    y: number;
    width: number;
    height: number;
    /** pt */
    fontSize: number;
    bold: boolean;
    /** Text lines the box holds (1 = never wraps). */
    lines?: number;
}

/** Cuts text to what fits on `lines` lines of a box (average glyph ≈ 0.55 em), with an ellipsis. */
function fitText(text: string, widthMm: number, fontSize: number, lines: number): string {
    const perLine = Math.max(4, Math.floor((widthMm * MM) / (fontSize * 0.6)));
    const max = perLine * lines;
    return text.length <= max ? text : `${text.slice(0, max - 1).trimEnd()}…`;
}

/**
 * Lays out one label: the text rows at their natural height, the barcode takes the rest, the whole stack centred.
 * Pure function: the PDF and the preview both draw these boxes, so what is on screen is what prints.
 */
export function labelBoxes(item: LabelItem, options: LabelOptions, width: number, height: number): LabelBox[] {
    const base = height >= 20 ? 6 : height >= 15 ? 5 : 4.5;
    // Shrink the name to one line, then the font, until the barcode keeps at least 30% of the label height.
    let nameLines = width >= 30 || options.wide ? 2 : 1;
    for (let fontSize = base; ; fontSize -= 0.5) {
        const boxes = stackLabel(item, options, width, height, fontSize, nameLines);
        const bar = boxes.find((b) => b.kind === 'barcode')!;
        if (bar.height >= height * 0.3 || fontSize <= 3.5) return boxes;
        if (nameLines > 1) {
            nameLines = 1;
            fontSize += 0.5;
        }
    }
}

function stackLabel(item: LabelItem, options: LabelOptions, width: number, height: number, fontSize: number, nameLines: number): LabelBox[] {
    const line = (fontSize * 1.15) / MM;
    const pad = 1;
    const gap = 0.3;
    const inner = width - pad * 2;
    const rows: Omit<LabelBox, 'x' | 'y'>[] = [];
    if (options.storeName) rows.push({ kind: 'store', text: fitText(options.storeName, inner, fontSize, 1), width: inner, height: line, fontSize, bold: true });
    if (options.showName) {
        const text = fitText(labelName(item), inner, fontSize, nameLines);
        const used = text.length > Math.floor((inner * MM) / (fontSize * 0.6)) ? nameLines : 1;
        rows.push({ kind: 'name', text, width: inner, height: line * used, fontSize, bold: false, lines: used });
    }
    const code = labelCode(item);
    const price = options.showPrice ? priceText(item, options) : null;
    const priceSize = fontSize + 1;
    const priceHeight = (priceSize * 1.15) / MM;
    const count = rows.length + 2 + (price ? 1 : 0);
    const textHeight = rows.reduce((s, r) => s + r.height, 0) + line + (price ? priceHeight : 0) + gap * (count - 1);
    const barHeight = Math.max(height - pad * 2 - textHeight, 1);
    rows.push({ kind: 'barcode', text: code, width: inner, height: barHeight, fontSize, bold: false });
    rows.push({ kind: 'code', text: code, width: inner, height: line, fontSize, bold: false });
    if (price) rows.push({ kind: 'price', text: price, width: inner, height: priceHeight, fontSize: priceSize, bold: true });

    const total = rows.reduce((s, r) => s + r.height, 0) + gap * (rows.length - 1);
    let y = Math.max(0, (height - total) / 2);
    return rows.map((r) => {
        const box = { ...r, x: pad, y };
        y += r.height + gap;
        return box;
    });
}

/** Where each label sits on its page, mm from the page's top-left, calibration included. */
export function labelSlots(paper: Paper, options: Pick<LabelOptions, 'wide' | 'offsetTop' | 'offsetLeft'>): { x: number; y: number; width: number; height: number }[] {
    const dx = Number(options.offsetLeft) || 0;
    const dy = Number(options.offsetTop) || 0;
    if (isRoll(paper) && options.wide) return [{ x: paper.left + dx, y: paper.top + dy, width: paper.page[0] - paper.left * 2, height: paper.height }];
    const slots = [];
    for (let r = 0; r < paper.rows; r++) {
        for (let c = 0; c < paper.columns; c++) {
            slots.push({ x: paper.left + c * (paper.width + paper.gapX) + dx, y: paper.top + r * (paper.height + paper.gapY) + dy, width: paper.width, height: paper.height });
        }
    }
    return slots;
}

/** Splits the labels into pages of the paper. */
export function paginate(paper: Paper, options: LabelOptions, labels: LabelItem[]): { item: LabelItem; slot: ReturnType<typeof labelSlots>[number] }[][] {
    const slots = labelSlots(paper, options);
    const pages = [];
    for (let i = 0; i < labels.length; i += slots.length) {
        pages.push(labels.slice(i, i + slots.length).map((item, j) => ({ item, slot: slots[j]! })));
    }
    return pages;
}

/** pdfmake document at the paper's exact size, every element absolutely positioned. Pure function (unit tested). */
export function labelDocument(items: LabelItem[], options: LabelOptions): Record<string, unknown> {
    const paper: Paper = papers[options.paper];
    const content: Record<string, unknown>[] = [];
    paginate(paper, options, expandLabels(items)).forEach((page, p) => {
        if (p > 0) content.push({ text: '', pageBreak: 'before' });
        for (const { item, slot } of page) {
            for (const box of labelBoxes(item, options, slot.width, slot.height)) {
                const at = { x: (slot.x + box.x) * MM, y: (slot.y + box.y) * MM };
                if (box.kind === 'barcode') {
                    content.push({ svg: barcodeSvg(box.text), width: box.width * MM, height: box.height * MM, absolutePosition: at });
                } else {
                    // A fixed-width column: pdfmake centres and wraps plain absolute text against the page, not the box.
                    content.push({
                        absolutePosition: at,
                        columns: [
                            {
                                width: box.width * MM,
                                text: box.text,
                                fontSize: box.fontSize,
                                bold: box.bold,
                                alignment: 'center',
                                ...(box.kind === 'code' ? { characterSpacing: 0.3 } : {}),
                                ...(box.kind === 'name' && (box.lines ?? 1) > 1 ? {} : { noWrap: true }),
                            },
                        ],
                    });
                }
            }
        }
    });

    return {
        pageSize: { width: paper.page[0] * MM, height: paper.page[1] * MM },
        pageMargins: [0, 0, 0, 0],
        content,
        defaultStyle: { font: 'Roboto', lineHeight: 1.05 },
        info: { title: 'Tem ma vach' },
    };
}

/** Opens the PDF in a new tab (to print) or downloads it. pdfmake is loaded lazily. */
export async function printLabels(items: LabelItem[], options: LabelOptions, mode: 'open' | 'download' = 'open'): Promise<void> {
    const [{ default: pdfMake }, { default: vfs }] = await Promise.all([import('pdfmake/build/pdfmake'), import('pdfmake/build/vfs_fonts')]);
    pdfMake.addVirtualFileSystem(vfs);
    const pdf = pdfMake.createPdf(labelDocument(items, options));
    if (mode === 'download') await pdf.download(`tem-ma-vach-${Date.now()}.pdf`);
    else await pdf.open();
}
