import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';

export type Direction = 'receipt' | 'payment';
export type Source = 'voucher' | 'order_payment' | 'supplier_payment' | 'shift_movement';
export type PartyKind = 'customer' | 'supplier' | 'staff' | 'carrier' | 'other';

export interface VoucherRow {
    source: Source;
    id: string;
    number: string | null;
    direction: Direction;
    type: { id: string | null; name: string };
    counts_in_pnl: boolean;
    party: { kind: PartyKind; id: string | null; name: string };
    method: string;
    amount: number;
    paid_at: string;
    document: { kind: 'order' | 'purchase' | 'shift'; id: string; number: string } | null;
    reference: string | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    voided_at: string | null;
    void_reason: string | null;
}

export interface VoucherType {
    id: string;
    direction: Direction;
    name: string;
    counts_in_pnl: boolean;
    description: string | null;
    is_active: boolean;
    vouchers_count?: number;
}

export const directionLabels: Record<Direction, string> = { receipt: 'Phiếu thu', payment: 'Phiếu chi' };

export const partyKinds: Record<PartyKind, string> = {
    customer: 'Khách hàng',
    supplier: 'Nhà cung cấp',
    staff: 'Nhân viên',
    carrier: 'Đơn vị vận chuyển',
    other: 'Đối tượng khác',
};

export const sourceLabels: Record<Source, string> = { voucher: 'Lập tay', order_payment: 'Tự động từ đơn hàng', supplier_payment: 'Tự động từ trả NCC', shift_movement: 'Tự động từ ca bán hàng' };

/** Code shown for a row: the voucher number, or the source document for automatic rows. */
export function voucherCode(v: VoucherRow): string {
    return v.number ?? (v.document ? `Tự động · ${v.document.number}` : 'Tự động');
}

export function documentLink(v: Pick<VoucherRow, 'document'>): string | null {
    if (!v.document) return null;
    if (v.document.kind === 'shift') return '/pos/shifts';
    return v.document.kind === 'order' ? `/orders/${v.document.id}` : `/purchasing/${v.document.id}`;
}

const digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

/** Reads 0–999; `full` keeps "không trăm" / "lẻ" when a higher group came before. */
function readTriple(n: number, full: boolean): string {
    const h = Math.floor(n / 100);
    const t = Math.floor((n % 100) / 10);
    const u = n % 10;
    const out: string[] = [];
    if (h > 0 || full) out.push(`${digits[h]} trăm`);
    if (t > 1) {
        out.push(`${digits[t]} mươi`);
        if (u === 1) out.push('mốt');
        else if (u === 5) out.push('lăm');
        else if (u === 4) out.push('tư');
        else if (u > 0) out.push(digits[u]);
    } else if (t === 1) {
        out.push('mười');
        if (u === 5) out.push('lăm');
        else if (u > 0) out.push(digits[u]);
    } else if (u > 0) {
        if (h > 0 || full) out.push('lẻ');
        out.push(digits[u]);
    }
    return out.join(' ');
}

/** Amount in Vietnamese words for vouchers ("Bằng chữ"), e.g. 1.250.000 → "Một triệu hai trăm năm mươi nghìn đồng". */
export function amountInWords(value: number): string {
    let n = Math.round(Math.abs(value));
    if (n === 0) return 'Không đồng';
    const units = ['', ' nghìn', ' triệu', ' tỷ'];
    const groups: number[] = [];
    while (n > 0) {
        groups.push(n % 1000);
        n = Math.floor(n / 1000);
    }
    const parts: string[] = [];
    for (let i = groups.length - 1; i >= 0; i--) {
        const g = groups[i];
        if (g === 0) {
            // "tỷ" is repeated for amounts of a thousand billion and more.
            if (i > 0 && i % 3 === 0 && parts.length) parts.push('tỷ');
            continue;
        }
        const scale = i === 0 ? '' : i % 3 === 0 ? ' tỷ' : units[i % 3];
        parts.push(readTriple(g, parts.length > 0) + scale);
    }
    const text = parts.join(' ').replace(/\s+/g, ' ').trim();
    return `${text.charAt(0).toUpperCase()}${text.slice(1)} đồng`;
}

function esc(value: string | null | undefined): string {
    return (value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

/** A5 receipt or payment voucher with signature boxes. Pure function (unit tested); printed through the browser. */
export function voucherHtml(v: VoucherRow, shopName: string): string {
    const receipt = v.direction === 'receipt';
    const title = receipt ? 'PHIẾU THU' : 'PHIẾU CHI';
    const who = receipt ? 'Người nộp' : 'Người nhận';
    const reason = [v.type.name, v.note].filter(Boolean).join(' · ');

    return `<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>${esc(voucherCode(v))}</title>
<style>
@page { size: A5 landscape; margin: 10mm; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #000; margin: 0; }
.head { display: flex; justify-content: space-between; align-items: flex-start; }
h1 { font-size: 20px; text-align: center; margin: 10px 0 2px; letter-spacing: 1px; }
.c { text-align: center; } .muted { color: #444; } .void { color: #b00; font-weight: bold; text-align: center; border: 2px solid #b00; padding: 2px 8px; display: inline-block; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
td { padding: 4px 0; vertical-align: top; }
td.k { width: 32%; color: #333; }
.sign { display: flex; justify-content: space-around; margin-top: 18px; text-align: center; }
.sign div { width: 30%; } .sign small { color: #555; }
</style></head><body>
<div class="head"><div><b>${esc(shopName)}</b></div><div class="muted">Số: <b>${esc(voucherCode(v))}</b></div></div>
<h1>${title}</h1>
<p class="c muted">Ngày ${formatDateTime(v.paid_at)}</p>
${v.voided_at ? `<p class="c"><span class="void">ĐÃ HỦY: ${esc(v.void_reason)}</span></p>` : ''}
<table>
<tr><td class="k">${who}</td><td><b>${esc(v.party.name)}</b> (${esc(partyKinds[v.party.kind])})</td></tr>
<tr><td class="k">Lý do</td><td>${esc(reason)}</td></tr>
<tr><td class="k">Số tiền</td><td><b>${formatMoney(v.amount)}</b></td></tr>
<tr><td class="k">Bằng chữ</td><td><i>${esc(amountInWords(v.amount))}</i></td></tr>
<tr><td class="k">Hình thức</td><td>${esc(paymentMethods[v.method] ?? v.method)}${v.reference ? ` · Tham chiếu: ${esc(v.reference)}` : ''}</td></tr>
${v.document ? `<tr><td class="k">Chứng từ gốc</td><td>${esc(v.document.number)}</td></tr>` : ''}
</table>
<div class="sign">
<div><b>Người lập phiếu</b><br><small>(Ký, ghi rõ họ tên)</small><br><br><br><br>${esc(v.created_by)}</div>
<div><b>${who}</b><br><small>(Ký, ghi rõ họ tên)</small></div>
<div><b>Thủ quỹ</b><br><small>(Ký, ghi rõ họ tên)</small></div>
</div>
</body></html>`;
}

export function printVoucher(v: VoucherRow, shopName: string): void {
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.open();
    doc.write(voucherHtml(v, shopName));
    doc.close();
    setTimeout(() => {
        frame.contentWindow!.focus();
        frame.contentWindow!.print();
        setTimeout(() => frame.remove(), 1000);
    }, 100);
}
