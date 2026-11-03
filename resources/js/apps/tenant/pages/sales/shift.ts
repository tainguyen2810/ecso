import { formatDateTime, formatNumber } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';
import type { CashShift } from '@/shared/types';

export const DENOMINATIONS = [500000, 200000, 100000, 50000, 20000, 10000, 5000, 2000, 1000, 500] as const;

/** Cash counted from a banknote tally: { "200000": 2, "50000": 1 } → 450 000. */
export function denominationTotal(counts: Record<string, number | string | null | undefined>): number {
    return Object.entries(counts).reduce((sum, [value, count]) => sum + Number(value) * Math.max(0, Math.floor(Number(count) || 0)), 0);
}

/** Positive = more cash than expected (thừa), negative = short (thiếu). */
export function cashDifference(counted: number, expected: number | string): number {
    return Math.round(counted - Number(expected));
}

export function differenceLabel(diff: number): string {
    if (diff === 0) return 'Khớp';
    return diff > 0 ? `Thừa ${formatNumber(diff)} ₫` : `Thiếu ${formatNumber(-diff)} ₫`;
}

function esc(value: string | null | undefined): string {
    return (value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

/** End-of-shift handover slip for an 80 mm receipt printer. Pure function (unit tested). */
export function shiftReportHtml(shift: CashShift, shopName: string): string {
    const t = shift.totals;
    const row = (label: string, value: string | number | null | undefined, cls = '') => `<tr class="${cls}"><td>${label}</td><td class="r">${formatNumber(value ?? 0)}</td></tr>`;
    const methods = Object.entries(t.by_method)
        .filter(([, v]) => Number(v) !== 0)
        .map(([m, v]) => row(esc(paymentMethods[m as keyof typeof paymentMethods] ?? m), v))
        .join('');
    const diff = shift.difference !== null ? Number(shift.difference) : null;
    const notes = Object.entries(shift.denominations ?? {})
        .sort(([a], [b]) => Number(b) - Number(a))
        .map(([v, n]) => `<tr><td>${formatNumber(v)} × ${n}</td><td class="r">${formatNumber(Number(v) * n)}</td></tr>`)
        .join('');

    return `<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>${esc(shift.number)}</title>
<style>
@page { size: 80mm auto; margin: 4mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; margin: 0 auto; width: 72mm; }
h1 { font-size: 15px; text-align: center; margin: 0 0 2px; } .c { text-align: center; } .r { text-align: right; }
table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
.b td { font-weight: bold; } .sign { display: flex; justify-content: space-between; margin-top: 18px; text-align: center; }
</style></head><body>
<h1>${esc(shopName)}</h1>
<p class="c"><b>BIÊN BẢN BÀN GIAO CA ${esc(shift.number)}</b><br>Thu ngân: ${esc(shift.cashier?.name)}<br>${formatDateTime(shift.opened_at)} → ${shift.closed_at ? formatDateTime(shift.closed_at) : 'đang mở'}</p>
<hr><table>
${row('Số đơn', t.orders)}${row('Doanh số', t.revenue)}${methods}${row('Tổng đã thu', t.collected, 'b')}
</table><hr><table>
${row('Tiền đầu ca', t.opening_cash)}${row('+ Thu tiền mặt', t.cash_sales)}${row('− Hoàn tiền mặt', t.cash_refunds)}${row('+ Nộp vào két', t.cash_in)}${row('− Rút khỏi két', t.cash_out)}${row('Tiền mặt theo hệ thống', shift.expected_cash ?? t.expected_cash, 'b')}
</table>${
        shift.counted_cash !== null
            ? `<hr><table>${notes}${row('Tiền mặt thực tế', shift.counted_cash, 'b')}<tr class="b"><td>Chênh lệch</td><td class="r">${diff === null ? '' : differenceLabel(diff)}</td></tr>${row('Nộp về', shift.handed_over)}${row('Để lại két', shift.kept_in_drawer)}</table>${shift.closing_note ? `<p>Ghi chú: ${esc(shift.closing_note)}</p>` : ''}`
            : ''
    }
<div class="sign"><div>Người giao<br><br><br>${esc(shift.cashier?.name)}</div><div>Người nhận<br><br><br>&nbsp;</div></div>
</body></html>`;
}

export function printShiftReport(shift: CashShift, shopName: string): void {
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.open();
    doc.write(shiftReportHtml(shift, shopName));
    doc.close();
    setTimeout(() => {
        frame.contentWindow!.focus();
        frame.contentWindow!.print();
        setTimeout(() => frame.remove(), 1000);
    }, 100);
}
