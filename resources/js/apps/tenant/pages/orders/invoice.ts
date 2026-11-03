import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { paymentMethodNames } from './labels';
import type { OrderDetail } from '@/shared/types';

/** Print templates: A4 portrait, A5 landscape (the standard sheet), and 80 mm / 57 mm thermal rolls. */
export type InvoiceSize = 'a4' | 'a5' | 'k80' | 'k57';

export const invoiceSizes: { value: InvoiceSize; label: string; hint: string }[] = [
    { value: 'a4', label: 'Mẫu in A4', hint: 'Khổ A4 dọc' },
    { value: 'a5', label: 'Mẫu in A5', hint: 'Khổ A5 ngang, mẫu tiêu chuẩn' },
    { value: 'k80', label: 'Mẫu in K80', hint: 'Máy in nhiệt 80 mm' },
    { value: 'k57', label: 'Mẫu in K57', hint: 'Máy in nhiệt 57 mm' },
];

function esc(value: string | null | undefined): string {
    return (value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

const lineTotal = (i: OrderDetail['items'][number]) => i.quantity * Number(i.unit_price) - Number(i.discount);

function totalsRows(order: OrderDetail): string {
    const payments = order.payments.filter((p) => !p.voided_at);
    const paid = payments.filter((p) => p.kind === 'payment').reduce((s, p) => s + Number(p.amount), 0);
    const due = Math.max(0, Number(order.total) - Number(order.paid_amount ?? 0));

    return `<tr><td>Tiền hàng</td><td class="r">${formatMoney(order.subtotal)}</td></tr>
${Number(order.shipping_fee) > 0 ? `<tr><td>Phí giao hàng</td><td class="r">${formatMoney(order.shipping_fee)}</td></tr>` : ''}
${Number(order.discount) > 0 ? `<tr><td>Giảm giá</td><td class="r">−${formatMoney(order.discount)}</td></tr>` : ''}
<tr class="total"><td>TỔNG CỘNG</td><td class="r">${formatMoney(order.total)}</td></tr>
${payments.map((p) => `<tr><td>${p.kind === 'refund' ? 'Hoàn tiền' : 'Đã trả'} (${esc(paymentMethodNames[p.method] ?? p.method)})</td><td class="r">${p.kind === 'refund' ? '−' : ''}${formatMoney(p.amount)}</td></tr>`).join('')}
${paid > 0 || due > 0 ? `<tr><td><b>Còn phải trả</b></td><td class="r"><b>${formatMoney(due)}</b></td></tr>` : ''}`;
}

/** Thermal roll (K80 / K57): one column, each line then "qty × price … amount". */
function rollHtml(order: OrderDetail, shopName: string, size: 'k80' | 'k57'): string {
    const rows = order.items
        .map(
            (i) => `<tr><td colspan="3" class="name">${esc(i.name)}${i.variant_name ? ` · ${esc(i.variant_name)}` : ''}</td></tr>
<tr class="sub"><td>${formatNumber(i.quantity)} × ${formatNumber(i.unit_price)}${Number(i.discount) > 0 ? ` <small>(−${formatNumber(i.discount)})</small>` : ''}</td><td></td><td class="r">${formatNumber(lineTotal(i))}</td></tr>`,
        )
        .join('');
    const k80 = size === 'k80';

    return `<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>${esc(order.external_order_id)}</title>
<style>
@page { size: ${k80 ? '80mm' : '57mm'} auto; margin: ${k80 ? '4mm' : '2mm'}; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: ${k80 ? '12px' : '10px'}; color: #000; margin: 0 auto; width: ${k80 ? '72mm' : '52mm'}; }
h1 { font-size: ${k80 ? '16px' : '13px'}; text-align: center; margin: 0 0 2px; }
.c { text-align: center; } .r { text-align: right; } .muted { color: #444; }
table { width: 100%; border-collapse: collapse; }
td { padding: 1px 0; vertical-align: top; }
.name { padding-top: 4px; font-weight: bold; }
.sub td { border-bottom: 1px dashed #999; padding-bottom: 3px; }
hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
.total td { font-size: ${k80 ? '15px' : '12px'}; font-weight: bold; padding-top: 4px; }
</style></head><body>
<h1>${esc(shopName)}</h1>
<p class="c muted">${esc(order.channel?.name ?? '')}</p>
<p class="c"><b>HÓA ĐƠN BÁN HÀNG</b><br>${esc(order.external_order_id)} · ${formatDateTime(order.placed_at)}</p>
${order.buyer_name ? `<p>Khách: <b>${esc(order.buyer_name)}</b>${order.buyer_phone ? ` · ${esc(order.buyer_phone)}` : ''}${order.shipping_address ? `<br>${esc(order.shipping_address)}` : ''}</p>` : ''}
<hr><table>${rows}</table>
<table>${totalsRows(order)}</table>
${order.buyer_note ? `<hr><p>Ghi chú: ${esc(order.buyer_note)}</p>` : ''}
<hr><p class="c">Cảm ơn quý khách!</p>
</body></html>`;
}

/** Sheet (A4 portrait / A5 landscape): header with shop and buyer, a table of lines, totals and signatures. */
function sheetHtml(order: OrderDetail, shopName: string, size: 'a4' | 'a5'): string {
    const a5 = size === 'a5';
    const rows = order.items
        .map(
            (i, n) => `<tr><td class="c">${n + 1}</td><td>${esc(i.name)}${i.variant_name ? `<br><small>${esc(i.variant_name)}</small>` : ''}</td><td class="c">${esc(i.variant?.sku ?? i.seller_sku ?? '')}</td><td class="r">${formatNumber(i.quantity)}</td><td class="r">${formatNumber(i.unit_price)}</td><td class="r">${Number(i.discount) > 0 ? formatNumber(i.discount) : ''}</td><td class="r">${formatNumber(lineTotal(i))}</td></tr>`,
        )
        .join('');

    return `<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>${esc(order.external_order_id)}</title>
<style>
@page { size: ${a5 ? 'A5 landscape' : 'A4'}; margin: ${a5 ? '8mm 10mm' : '12mm'}; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: ${a5 ? '11px' : '12px'}; color: #000; margin: 0; }
.head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 8px; }
.head h1 { font-size: ${a5 ? '15px' : '18px'}; margin: 0; }
.title { text-align: center; font-size: ${a5 ? '15px' : '18px'}; font-weight: bold; margin: 6px 0 2px; }
.c { text-align: center; } .r { text-align: right; } .muted { color: #444; }
.info { display: flex; gap: 24px; margin: 6px 0 8px; } .info div { flex: 1; }
table.lines { width: 100%; border-collapse: collapse; }
table.lines th, table.lines td { border: 1px solid #999; padding: 3px 5px; vertical-align: top; }
table.lines th { background: #eee; font-weight: bold; }
.bottom { display: flex; justify-content: space-between; gap: 24px; margin-top: 8px; }
table.sum { width: 45%; border-collapse: collapse; } table.sum td { padding: 2px 0; }
.total td { font-size: ${a5 ? '13px' : '15px'}; font-weight: bold; border-top: 1px solid #000; padding-top: 4px; }
.sign { display: flex; justify-content: space-around; margin-top: ${a5 ? '10px' : '24px'}; text-align: center; }
.sign div { width: 40%; } .sign small { display: block; color: #444; margin-bottom: ${a5 ? '28px' : '48px'}; }
</style></head><body>
<div class="head"><div><h1>${esc(shopName)}</h1><div class="muted">${esc(order.channel?.name ?? '')}</div></div><div class="r">Số: <b>${esc(order.external_order_id)}</b><br>${formatDateTime(order.placed_at)}</div></div>
<div class="title">HÓA ĐƠN BÁN HÀNG</div>
<div class="info">
<div>Khách hàng: <b>${esc(order.buyer_name ?? order.customer?.name ?? 'Khách lẻ')}</b>${order.buyer_phone ? `<br>Điện thoại: ${esc(order.buyer_phone)}` : ''}</div>
<div>${order.shipping_address ? `Địa chỉ: ${esc(order.shipping_address)}` : ''}${order.created_by ? `<br>Nhân viên: ${esc(order.created_by)}` : ''}</div>
</div>
<table class="lines"><thead><tr><th style="width:5%">STT</th><th>Sản phẩm</th><th style="width:14%">Mã SKU</th><th style="width:7%">SL</th><th style="width:13%">Đơn giá</th><th style="width:11%">Chiết khấu</th><th style="width:14%">Thành tiền</th></tr></thead><tbody>${rows}</tbody></table>
<div class="bottom"><div>${order.buyer_note ? `Ghi chú: ${esc(order.buyer_note)}` : ''}</div><table class="sum">${totalsRows(order)}</table></div>
<div class="sign"><div><b>Người mua hàng</b><small>(Ký, ghi rõ họ tên)</small></div><div><b>Người bán hàng</b><small>(Ký, ghi rõ họ tên)</small></div></div>
</body></html>`;
}

/**
 * Sales receipt as a self-contained HTML page in the chosen template. Pure function (unit tested); printing goes
 * through the browser so any printer driver works without plugins.
 */
export function invoiceHtml(order: OrderDetail, shopName: string, size: InvoiceSize = 'k80'): string {
    return size === 'a4' || size === 'a5' ? sheetHtml(order, shopName, size) : rollHtml(order, shopName, size);
}

/** Several receipts in one print job, one per page. */
export function invoicesHtml(orders: OrderDetail[], shopName: string, size: InvoiceSize = 'k80'): string {
    const pages = orders.map((o) => invoiceHtml(o, shopName, size));
    const body = (html: string) => html.slice(html.indexOf('<body>') + 6, html.lastIndexOf('</body>'));
    const head = pages[0]!.slice(0, pages[0]!.indexOf('<body>') + 6);

    return `${head}${pages.map((p) => `<section style="page-break-after: always">${body(p)}</section>`).join('')}</body></html>`;
}

/** Default template of this business on this browser (Cài đặt → Mẫu in). A5 landscape unless changed. */
export function defaultInvoiceSize(tenantId: string | undefined): InvoiceSize {
    try {
        const v = localStorage.getItem(`ecso.print.size.${tenantId ?? 'default'}`);
        return invoiceSizes.some((s) => s.value === v) ? (v as InvoiceSize) : 'a5';
    } catch {
        return 'a5';
    }
}

export function setDefaultInvoiceSize(tenantId: string | undefined, size: InvoiceSize): void {
    try {
        localStorage.setItem(`ecso.print.size.${tenantId ?? 'default'}`, size);
    } catch {
        // Private mode: the choice lasts for this visit only.
    }
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
