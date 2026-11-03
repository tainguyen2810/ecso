import { formatDate, formatNumber } from '@/shared/lib/utils';
import type { PurchaseOrderData } from '@/shared/types';

function esc(value: string | null | undefined): string {
    return (value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

/** A4 purchase order to send to the supplier (print or save as PDF). Pure function (unit tested). */
export function purchaseOrderHtml(po: PurchaseOrderData, shop: { name: string; address?: string | null; phone?: string | null }): string {
    const s = po.supplier;
    const rows = (po.lines ?? [])
        .map((l, i) => {
            const amount = l.quantity * Number(l.unit_cost);
            return `<tr><td class="c">${i + 1}</td><td>${esc(l.variant?.sku)}</td><td>${esc(l.variant?.name)}</td><td class="r">${formatNumber(l.quantity)}</td><td class="r">${formatNumber(l.unit_cost)}</td><td class="r">${Number(l.tax_rate)}%</td><td class="r">${formatNumber(amount)}</td></tr>`;
        })
        .join('');
    const extra = Number(po.extra_cost) > 0 && po.extra_cost_to_supplier ? `<tr><td colspan="6" class="r">Chi phí${po.extra_cost_note ? ` (${esc(po.extra_cost_note)})` : ''}</td><td class="r">${formatNumber(po.extra_cost)}</td></tr>` : '';

    return `<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>${esc(po.number)}</title>
<style>
@page { size: A4; margin: 15mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; }
h1 { font-size: 18px; text-align: center; margin: 12px 0 2px; } .c { text-align: center; } .r { text-align: right; }
.head { display: flex; justify-content: space-between; gap: 24px; } .head div { flex: 1; }
table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; } table.items th { background: #eee; }
.sum td { border: none !important; padding: 2px 6px; } .b { font-weight: bold; }
.sign { display: flex; justify-content: space-around; margin-top: 32px; text-align: center; } .sign div { width: 40%; }
</style></head><body>
<div class="head">
<div><b>${esc(shop.name)}</b>${shop.address ? `<br>${esc(shop.address)}` : ''}${shop.phone ? `<br>ĐT: ${esc(shop.phone)}` : ''}</div>
<div class="r">Số: <b>${esc(po.number)}</b><br>Ngày: ${formatDate(po.ordered_at ?? po.created_at)}</div>
</div>
<h1>ĐƠN ĐẶT HÀNG</h1>
<p class="c">${po.expected_at ? `Ngày giao dự kiến: ${formatDate(po.expected_at)}` : ''}</p>
<p>Kính gửi: <b>${esc(s?.name)}</b>${s?.tax_code ? ` · MST: ${esc(s.tax_code)}` : ''}<br>
${s?.address ? `Địa chỉ: ${esc(s.address)}<br>` : ''}${s?.contact_name || s?.phone ? `Liên hệ: ${esc(s?.contact_name)}${s?.phone ? ` · ${esc(s.phone)}` : ''}<br>` : ''}
Giao hàng tại: ${esc(po.warehouse?.name)}</p>
<table class="items"><thead><tr><th>STT</th><th>Mã hàng</th><th>Tên hàng</th><th>SL</th><th>Đơn giá</th><th>VAT</th><th>Thành tiền</th></tr></thead>
<tbody>${rows}</tbody>
<tbody class="sum">
<tr><td colspan="6" class="r">Cộng tiền hàng</td><td class="r">${formatNumber(po.subtotal)}</td></tr>
<tr><td colspan="6" class="r">Thuế GTGT</td><td class="r">${formatNumber(po.tax_amount)}</td></tr>${extra}
<tr class="b"><td colspan="6" class="r">Tổng cộng</td><td class="r">${formatNumber(po.total)}</td></tr>
</tbody></table>
${po.note ? `<p>Ghi chú: ${esc(po.note)}</p>` : ''}
<div class="sign"><div><b>Nhà cung cấp</b><br>(Ký, ghi rõ họ tên)</div><div><b>Người đặt hàng</b><br>(Ký, ghi rõ họ tên)<br><br><br><br>${esc(po.created_by)}</div></div>
</body></html>`;
}

export function printPurchaseOrder(po: PurchaseOrderData, shop: { name: string; address?: string | null; phone?: string | null }): void {
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.open();
    doc.write(purchaseOrderHtml(po, shop));
    doc.close();
    setTimeout(() => {
        frame.contentWindow!.focus();
        frame.contentWindow!.print();
        setTimeout(() => frame.remove(), 1000);
    }, 100);
}
