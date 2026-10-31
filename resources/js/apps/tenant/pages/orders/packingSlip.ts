import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { marketplaceNames } from './labels';

export interface PackingSlipData {
    id: string;
    external_order_id: string;
    shop: string | null;
    marketplace: string;
    placed_at: string | null;
    ship_by_at: string | null;
    buyer_name: string | null;
    buyer_phone: string | null;
    shipping_address: string | null;
    carrier: string | null;
    tracking_number: string | null;
    is_cod: boolean;
    total: string;
    note: string | null;
    warehouse: string | null;
    items: { sku: string | null; name: string; variant_name: string | null; quantity: number }[];
}

type Content = Record<string, unknown> | string;

/** pdfmake document definition: one A6-ish slip per order, page break between orders. Pure function (unit tested). */
export function packingSlipDocument(slips: PackingSlipData[]): Record<string, unknown> {
    const content: Content[] = [];
    slips.forEach((s, index) => {
        const totalQty = s.items.reduce((sum, i) => sum + i.quantity, 0);
        content.push(
            {
                columns: [
                    { text: 'PHIẾU ĐÓNG GÓI', style: 'title' },
                    { text: `${marketplaceNames[s.marketplace] ?? s.marketplace} · ${s.shop ?? ''}`, alignment: 'right', style: 'muted' },
                ],
                ...(index > 0 ? { pageBreak: 'before' } : {}),
            },
            { text: `Mã đơn: ${s.external_order_id}`, style: 'orderNo', margin: [0, 6, 0, 2] },
            {
                columns: [
                    { text: `Đặt lúc: ${formatDateTime(s.placed_at)}`, style: 'muted' },
                    { text: `Hạn giao: ${formatDateTime(s.ship_by_at)}`, style: 'muted', alignment: 'right' },
                ],
            },
            {
                margin: [0, 8, 0, 8],
                table: {
                    widths: ['*'],
                    body: [[{
                        stack: [
                            { text: `Người nhận: ${s.buyer_name ?? '—'}  ${s.buyer_phone ?? ''}` },
                            { text: `Địa chỉ: ${s.shipping_address ?? '—'}` },
                            { text: `Vận chuyển: ${s.carrier ?? '—'}  ·  Mã vận đơn: ${s.tracking_number ?? '—'}` },
                        ],
                    }]],
                },
                layout: 'lightHorizontalLines',
            },
            {
                table: {
                    headerRows: 1,
                    widths: [70, '*', 30],
                    body: [
                        [{ text: 'SKU', bold: true }, { text: 'Sản phẩm', bold: true }, { text: 'SL', bold: true, alignment: 'right' }],
                        ...s.items.map((i) => [
                            i.sku ?? '(chưa map)',
                            i.variant_name ? `${i.name} — ${i.variant_name}` : i.name,
                            { text: String(i.quantity), alignment: 'right' },
                        ]),
                        [{ text: '', border: [false, false, false, false] }, { text: 'Tổng số lượng', bold: true, alignment: 'right' }, { text: String(totalQty), bold: true, alignment: 'right' }],
                    ],
                },
            },
            { text: s.is_cod ? `Thu hộ (COD): ${formatMoney(s.total)}` : 'Đã thanh toán trên sàn', margin: [0, 8, 0, 0], bold: s.is_cod },
            ...(s.note ? [{ text: `Ghi chú của khách: ${s.note}`, italics: true, margin: [0, 4, 0, 0] }] : []),
            { text: `Kho xuất: ${s.warehouse ?? '—'}`, style: 'muted', margin: [0, 4, 0, 0] },
        );
    });

    return {
        pageSize: 'A5',
        pageMargins: [24, 24, 24, 24],
        defaultStyle: { font: 'Roboto', fontSize: 9 },
        styles: { title: { fontSize: 13, bold: true }, orderNo: { fontSize: 11, bold: true }, muted: { color: '#666666', fontSize: 8 } },
        content,
        info: { title: `Phieu-dong-goi-${slips.length}-don` },
    };
}

/** Builds and downloads the PDF in the browser (Roboto covers Vietnamese). Loaded lazily: pdfmake is large. */
export async function downloadPackingSlips(slips: PackingSlipData[]): Promise<void> {
    const [{ default: pdfMake }, { default: vfs }] = await Promise.all([import('pdfmake/build/pdfmake'), import('pdfmake/build/vfs_fonts')]);
    pdfMake.addVirtualFileSystem(vfs);
    const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '');
    await pdfMake.createPdf(packingSlipDocument(slips)).download(`phieu-dong-goi-${stamp}.pdf`);
}
