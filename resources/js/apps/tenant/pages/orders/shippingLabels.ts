
/** Paper for marketplace labels: A6 (thermal printer, one label a sheet), A5 (2 a page), A4 (4 a page). */
export type LabelPaper = 'a6' | 'a5' | 'a4';

export const labelPapers: { value: LabelPaper; label: string; hint: string }[] = [
    { value: 'a6', label: 'A6', hint: 'Máy in nhiệt 100×150, mỗi phiếu một tờ' },
    { value: 'a5', label: 'A5', hint: '2 phiếu một trang' },
    { value: 'a4', label: 'A4', hint: '4 phiếu một trang' },
];

const MM = 72 / 25.4;
/** Sheet size in points and the grid of labels on it. */
const sheets: Record<Exclude<LabelPaper, 'a6'>, { width: number; height: number; cols: number; rows: number }> = {
    a5: { width: 210 * MM, height: 148 * MM, cols: 2, rows: 1 },
    a4: { width: 210 * MM, height: 297 * MM, cols: 2, rows: 2 },
};

/** Where one label goes in a cell: as large as fits, aspect kept, centred. */
export function fitInCell(page: { width: number; height: number }, cell: { x: number; y: number; width: number; height: number }) {
    const scale = Math.min(cell.width / page.width, cell.height / page.height);
    const width = page.width * scale;
    const height = page.height * scale;
    return { x: cell.x + (cell.width - width) / 2, y: cell.y + (cell.height - height) / 2, width, height };
}

const fromBase64 = (b64: string) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));

/**
 * One PDF of the labels of many orders (Shopee and TikTok alike), in the given order. A6 keeps each label page as the
 * marketplace made it; A5 and A4 put 2 or 4 on a sheet, left to right then top to bottom.
 */
export async function mergeLabels(files: string[], paper: LabelPaper): Promise<Uint8Array> {
    // pdf-lib (~500 KB) is loaded only when labels are printed, not with the order pages.
    const { PDFDocument } = await import('pdf-lib');
    const out = await PDFDocument.create();
    const sources = await Promise.all(files.map((f) => PDFDocument.load(fromBase64(f), { ignoreEncryption: true })));
    if (paper === 'a6') {
        for (const src of sources) {
            const pages = await out.copyPages(src, src.getPageIndices());
            pages.forEach((p) => out.addPage(p));
        }
        return out.save();
    }
    const sheet = sheets[paper];
    const cell = { width: sheet.width / sheet.cols, height: sheet.height / sheet.rows };
    const perSheet = sheet.cols * sheet.rows;
    const labels = (await Promise.all(sources.map((src) => out.embedPages(src.getPages())))).flat();
    labels.forEach((label, i) => {
        const page = i % perSheet === 0 ? out.addPage([sheet.width, sheet.height]) : out.getPage(out.getPageCount() - 1);
        const slot = i % perSheet;
        const col = slot % sheet.cols;
        const row = Math.floor(slot / sheet.cols);
        // PDF coordinates start bottom-left: the first row is at the top.
        page.drawPage(label, fitInCell(label, { x: col * cell.width, y: sheet.height - (row + 1) * cell.height, width: cell.width, height: cell.height }));
    });
    return out.save();
}

/** Opens the browser's print dialog on a PDF (hidden frame); falls back to a new tab when the frame cannot print. */
export function printPdf(bytes: Uint8Array): void {
    const url = URL.createObjectURL(new Blob([bytes as BlobPart], { type: 'application/pdf' }));
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    frame.src = url;
    frame.onload = () => {
        try {
            frame.contentWindow?.focus();
            frame.contentWindow?.print();
        } catch {
            window.open(url, '_blank');
        }
        setTimeout(() => {
            frame.remove();
            URL.revokeObjectURL(url);
        }, 60_000);
    };
    document.body.appendChild(frame);
}
