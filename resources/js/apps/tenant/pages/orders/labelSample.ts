import type { LabelExtras } from './labelExtras';
import type { LabelExtrasSettings } from '@/shared/stores/settings';

const MM = 72 / 25.4;

/**
 * "In thử phiếu mẫu": a made-up 100×150 mm label laid out like the marketplaces' (carrier, barcode, sender and
 * recipient, sort code, items with a blank area, COD), so the shop sees where its text lands before printing real
 * orders.
 */
export async function sampleLabel(): Promise<Uint8Array> {
    const { PDFDocument, rgb } = await import('pdf-lib');
    const { loadFonts } = await import('./labelExtras');
    const doc = await PDFDocument.create();
    const W = 100 * MM;
    const H = 150 * MM;
    const page = doc.addPage([W, H]);
    const { regular: font, bold } = await loadFonts(doc);
    const black = rgb(0, 0, 0);
    // y from the top, as on paper.
    const text = (t: string, x: number, top: number, size: number, b = false) => page.drawText(t, { x, y: H - top, size, font: b ? bold : font, color: black });
    const hline = (top: number) => page.drawLine({ start: { x: 4, y: H - top }, end: { x: W - 4, y: H - top }, thickness: 0.8, color: black, dashArray: [3, 2] });
    const vline = (x: number, from: number, to: number) => page.drawLine({ start: { x, y: H - from }, end: { x, y: H - to }, thickness: 0.8, color: black, dashArray: [3, 2] });
    page.drawRectangle({ x: 4, y: 4, width: W - 8, height: H - 8, borderColor: black, borderWidth: 1 });

    text('EXPRESS', 10, 26, 18, true);
    for (let i = 0, x = 130; x < W - 12; i++) {
        const w = [1.2, 0.6, 2, 0.8, 1.5][i % 5];
        page.drawRectangle({ x, y: H - 34, width: w, height: 24, color: black });
        x += w + [0.8, 1.4, 0.7, 1.1][i % 4];
    }
    text('Mã vận đơn: DEMO000000001', 130, 44, 7);
    text('Mã đơn hàng: 2611DEMO0001', 130, 53, 7);
    hline(60);
    vline(W / 2, 60, 118);
    text('Từ:', 10, 70, 7, true);
    text('Shop Mẫu Demo', 10, 80, 7);
    text('12 Đường Số 1, Phường A', 10, 89, 7);
    text('Đến:', W / 2 + 6, 70, 7, true);
    text('Nguyễn Văn An', W / 2 + 6, 80, 7);
    text('34 Đường Số 2, Phường B', W / 2 + 6, 89, 7);
    hline(118);
    text('HCA-00-00-XX00', 30, 140, 18, true);
    hline(150);
    text('Nội dung hàng (Tổng SL sản phẩm: 3)', 10, 162, 7, true);
    text('1. Bàn phím cơ không dây, SL: 1', 10, 172, 7);
    text('2. Chuột quang văn phòng, SL: 2', 10, 181, 7);
    // QR-like square on the right of the items.
    const qx = W - 80;
    for (let r = 0; r < 9; r++) for (let c = 0; c < 9; c++) if ((r * 7 + c * 3) % 4 < 2) page.drawRectangle({ x: qx + c * 7, y: H - 160 - r * 7 - 7, width: 7, height: 7, color: black });
    text('Ngày đặt hàng:', qx, 240, 7);
    text('26-11-2026', qx, 252, 9, true);
    hline(H - 95);
    text('Tiền thu Người nhận:', 10, H - 82, 7);
    text('250,000 VND', 18, H - 58, 16, true);
    page.drawRectangle({ x: W / 2 + 4, y: 38, width: W / 2 - 14, height: 44, borderColor: black, borderWidth: 0.8 });
    text('Chữ ký người nhận', W / 2 + 22, H - 72, 7, true);
    hline(H - 30);
    text('Gọi 1900 0000  |  Thông tin của đơn vị vận chuyển', 10, H - 14, 6.5);
    return doc.save();
}

/** The extras for the made-up order, from the settings being edited (whatever is switched on). */
export function sampleExtras(s: LabelExtrasSettings): LabelExtras {
    const vars: Record<string, string> = { ten_khach: 'Nguyễn Văn An', ma_don: '2611DEMO0001', tong_tien: '250.000đ', ngay_dat: '26/11/2026', ten_shop: 'Shop Mẫu' };
    const lines = (s.notice_text ?? '')
        .replace(/\{(\w+)\}/g, (m, k: string) => vars[k] ?? m)
        .split(/\r?\n/)
        .map((l) => l.trim())
        .filter(Boolean)
        .slice(0, 4);
    return {
        picklist: s.picklist
            ? [
                  { sku: 'KB-K100-DEN', name: 'Bàn phím K100 đen', quantity: 1 },
                  { sku: 'MS-M20-TRANG', name: 'Chuột M20 trắng', quantity: 2 },
              ]
            : null,
        note: s.note ? 'Khách nhắn: gói kỹ giúp em · Ghi chú: tặng kèm miếng lót chuột' : null,
        warehouse: s.warehouse ? 'Kho chính' : null,
        notice: s.notice && lines.length ? { lines, box: s.notice_box } : null,
        size: s.size,
    };
}
