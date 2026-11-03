import { barcodeSvg } from './barcode';
import { formatMoney } from '@/shared/lib/utils';

export interface LabelItem {
    sku: string;
    barcode: string | null;
    productName: string;
    variantName: string | null;
    price: string | number | null;
    quantity: number;
}

export interface LabelOptions {
    layout: LabelLayoutKey;
    showName: boolean;
    showPrice: boolean;
    /** Store name printed on top, optional. */
    heading: string;
}

interface LabelLayout {
    label: string;
    /** Label size in mm. */
    width: number;
    height: number;
    /** Sheet: labels per row and the page grid; roll printers print one label per page. */
    sheet?: { columns: number; rows: number; marginTop: number; marginLeft: number; gapX: number; gapY: number };
}

export const labelLayouts = {
    '50x30': { label: 'Tem cuộn 50 × 30 mm', width: 50, height: 30 },
    '40x30': { label: 'Tem cuộn 40 × 30 mm', width: 40, height: 30 },
    '35x22': { label: 'Tem cuộn 35 × 22 mm', width: 35, height: 22 },
    'a4-65': {
        label: 'Giấy A4, 65 tem (38 × 21 mm)',
        width: 38.1,
        height: 21.2,
        sheet: { columns: 5, rows: 13, marginTop: 10.7, marginLeft: 4.7, gapX: 2.5, gapY: 0 },
    },
} satisfies Record<string, LabelLayout>;

export type LabelLayoutKey = keyof typeof labelLayouts;

const MM = 72 / 25.4;

/** The code printed on the label: the barcode when the SKU has one, else the SKU itself. */
export function labelCode(item: Pick<LabelItem, 'sku' | 'barcode'>): string {
    return (item.barcode ?? '').trim() || item.sku;
}

/** One entry per printed label, in order. */
export function expandLabels(items: LabelItem[]): LabelItem[] {
    return items.flatMap((item) => Array.from({ length: Math.max(0, Math.floor(item.quantity)) }, () => item));
}

function labelStack(item: LabelItem, options: LabelOptions, width: number, height: number): Record<string, unknown> {
    const name = [item.productName, item.variantName].filter(Boolean).join(' - ');
    const small = height < 25 * MM;
    const nameSize = small ? 5.5 : 6.5;
    const lines: Record<string, unknown>[] = [];
    if (options.heading) lines.push({ text: options.heading, fontSize: nameSize, bold: true, alignment: 'center', noWrap: true });
    if (options.showName) lines.push({ text: name, fontSize: nameSize, alignment: 'center', maxHeight: nameSize * 2.4, margin: [0, 0, 0, 1] });

    const textRows = (options.heading ? 1 : 0) + (options.showName ? 2 : 0) + 1 + (options.showPrice ? 1 : 0);
    const barHeight = Math.max(height - textRows * (nameSize + 1.5) - 4, height * 0.3);
    lines.push({ svg: barcodeSvg(labelCode(item)), width: width - 4, height: barHeight, alignment: 'center' });
    lines.push({ text: labelCode(item), fontSize: nameSize, alignment: 'center', characterSpacing: 0.3 });
    if (options.showPrice && item.price !== null && Number(item.price) > 0) {
        lines.push({ text: formatMoney(item.price), fontSize: small ? 7 : 8.5, bold: true, alignment: 'center' });
    }

    return { stack: lines, width, margin: [2, 2, 2, 0] };
}

/** pdfmake document: a page per label for roll printers, a grid for A4 sheets. Pure function (unit tested). */
export function labelDocument(items: LabelItem[], options: LabelOptions): Record<string, unknown> {
    const layout: LabelLayout = labelLayouts[options.layout];
    const labels = expandLabels(items);
    const width = layout.width * MM;
    const height = layout.height * MM;

    if (!layout.sheet) {
        return {
            pageSize: { width, height },
            pageMargins: [0, 0, 0, 0],
            content: labels.map((item, i) => ({ ...labelStack(item, options, width - 4, height), ...(i > 0 ? { pageBreak: 'before' } : {}) })),
            defaultStyle: { font: 'Roboto', lineHeight: 1 },
            info: { title: 'Tem ma vach' },
        };
    }

    const { columns, rows, marginTop, marginLeft, gapX, gapY } = layout.sheet;
    const perPage = columns * rows;
    const content: Record<string, unknown>[] = [];
    for (let page = 0; page * perPage < labels.length; page++) {
        const body: Record<string, unknown>[][] = [];
        for (let r = 0; r < rows; r++) {
            const row: Record<string, unknown>[] = [];
            for (let c = 0; c < columns; c++) {
                const item = labels[page * perPage + r * columns + c];
                row.push(item ? labelStack(item, options, width - 4, height) : { text: '' });
            }
            body.push(row);
            if (page * perPage + (r + 1) * columns >= labels.length) break;
        }
        content.push({
            table: { widths: Array(columns).fill(width - 4), heights: height - 2, body, dontBreakRows: true },
            layout: { hLineWidth: () => 0, vLineWidth: () => 0, paddingLeft: () => 0, paddingRight: () => gapX * MM + 4, paddingTop: () => 0, paddingBottom: () => gapY * MM + 2 },
            ...(page > 0 ? { pageBreak: 'before' } : {}),
        });
    }

    return {
        pageSize: 'A4',
        pageMargins: [marginLeft * MM, marginTop * MM, 0, 0],
        content,
        defaultStyle: { font: 'Roboto', lineHeight: 1 },
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
