import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { paymentMethodNames } from './labels';
import type { OrderDetail } from '@/shared/types';

export type InvoiceSize = 'k80' | 'a5';

function esc(value: string | null | undefined): string {
    return (value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

/**
 * Sales receipt as a self-contained HTML page: K80 (80 mm thermal printer) or A5. Pure function (unit tested);
 * printing goes through the browser so any printer driver works without plugins.
 */
export function invoiceHtml(order: OrderDetail, shopName: string, size: InvoiceSize = 'k80'): string {
    const payments = order.payments.filter((p) => !p.voided_at);
    const paid = payments.filter((p) => p.kind === 'payment').reduce((s, p) => s + Number(p.amount), 0);
    const due = Math.max(0, Number(order.total) - Number(order.paid_amount ?? 0));
    const rows = order.items
        .map(
            (i) => `<tr><td colspan="3" class="name">${esc(i.name)}${i.variant_name ? ` · ${esc(i.variant_name)}` : ''}</td></tr>
<tr class="sub"><td>${formatNumber(i.quantity)} × ${formatNumber(i.unit_price)}${Number(i.discount) > 0 ? ` <small>(−${formatNumber(i.discount)})</small>` : ''}</td><td></td><td class="r">${formatNumber(i.quantity * Number(i.unit_price) - Number(i.discount))}</td></tr>`,
        )
        .join('');
    const width = size === 'k80' ? '72mm' : '138mm';

    return `<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>${esc(order.external_order_id)}</title>
<style>
@page { size: ${size === 'k80' ? '80mm auto' : 'A5'}; margin: ${size === 'k80' ? '4mm' : '10mm'}; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: ${size === 'k80' ? '12px' : '13px'}; color: #000; margin: 0 auto; width: ${width}; }
h1 { font-size: 16px; text-align: center; margin: 0 0 2px; }
.c { text-align: center; } .r { text-align: right; } .muted { color: #444; }
table { width: 100%; border-collapse: collapse; }
td { padding: 1px 0; vertical-align: top; }
.name { padding-top: 4px; font-weight: bold; }
.sub td { border-bottom: 1px dashed #999; padding-bottom: 3px; }
hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
.total td { font-size: 15px; font-weight: bold; padding-top: 4px; }
</style></head><body>
<h1>${esc(shopName)}</h1>
<p class="c muted">${esc(order.channel?.name ?? '')}</p>
<p class="c"><b>HÓA ĐƠN BÁN HÀNG</b><br>${esc(order.external_order_id)} · ${formatDateTime(order.placed_at)}</p>
${order.buyer_name ? `<p>Khách: <b>${esc(order.buyer_name)}</b>${order.buyer_phone ? ` · ${esc(order.buyer_phone)}` : ''}${order.shipping_address ? `<br>${esc(order.shipping_address)}` : ''}</p>` : ''}
<hr><table>${rows}</table>
<table>
<tr><td>Tiền hàng</td><td class="r">${formatMoney(order.subtotal)}</td></tr>
${Number(order.shipping_fee) > 0 ? `<tr><td>Phí giao hàng</td><td class="r">${formatMoney(order.shipping_fee)}</td></tr>` : ''}
${Number(order.discount) > 0 ? `<tr><td>Giảm giá</td><td class="r">−${formatMoney(order.discount)}</td></tr>` : ''}
<tr class="total"><td>TỔNG CỘNG</td><td class="r">${formatMoney(order.total)}</td></tr>
${payments.map((p) => `<tr><td>${p.kind === 'refund' ? 'Hoàn tiền' : 'Đã trả'} (${esc(paymentMethodNames[p.method] ?? p.method)})</td><td class="r">${p.kind === 'refund' ? '−' : ''}${formatMoney(p.amount)}</td></tr>`).join('')}
${paid > 0 || due > 0 ? `<tr><td><b>Còn phải trả</b></td><td class="r"><b>${formatMoney(due)}</b></td></tr>` : ''}
</table>
${order.buyer_note ? `<hr><p>Ghi chú: ${esc(order.buyer_note)}</p>` : ''}
<hr><p class="c">Cảm ơn quý khách!</p>
</body></html>`;
}

/** Several receipts in one print job, one per page. */
export function invoicesHtml(orders: OrderDetail[], shopName: string, size: InvoiceSize = 'k80'): string {
    const pages = orders.map((o) => invoiceHtml(o, shopName, size));
    const body = (html: string) => html.slice(html.indexOf('<body>') + 6, html.lastIndexOf('</body>'));
    const head = pages[0]!.slice(0, pages[0]!.indexOf('<body>') + 6);

    return `${head}${pages.map((p) => `<section style="page-break-after: always">${body(p)}</section>`).join('')}</body></html>`;
}

export function printInvoice(order: OrderDetail | OrderDetail[], shopName: string, size: InvoiceSize = 'k80'): void {
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.open();
    doc.write(Array.isArray(order) ? invoicesHtml(order, shopName, size) : invoiceHtml(order, shopName, size));
    doc.close();
    setTimeout(() => {
        frame.contentWindow!.focus();
        frame.contentWindow!.print();
        setTimeout(() => frame.remove(), 1000);
    }, 100);
}
