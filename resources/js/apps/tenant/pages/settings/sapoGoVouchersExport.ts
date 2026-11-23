/**
 * The "Lấy sổ quỹ Sapo" bookmark: like "Lấy nhập hàng Sapo" (sapoGoPurchasesExport.ts) it runs inside the Sapo GO admin
 * page the user is signed in to and reads the lists that page itself loads: /admin/receipt_vouchers.json (Phiếu thu)
 * and /admin/payment_vouchers.json (Phiếu chi). Vouchers Sapo made by itself for an order, a purchase order or a return
 * are left out (their payments came over with those documents). It saves one file, which the user uploads to EzChat
 * Bán hàng (read by SapoVoucherImporter). Nothing is sent anywhere else and the Sapo password is never needed.
 *
 * Sapo's own field names live only here: several names are tried for each value because Sapo GO's internal API is not
 * documented. The function becomes a bookmark with its own source, so it must not use anything from outside its body.
 */
export async function sapoGoVouchersExport(): Promise<void> {
    const box = document.createElement('div');
    box.setAttribute('style', 'position:fixed;z-index:2147483647;top:16px;right:16px;max-width:440px;background:#1e293b;color:#fff;padding:12px 16px;border-radius:8px;font:14px/1.5 sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3);white-space:pre-line');
    document.body.appendChild(box);
    const say = (text: string) => {
        box.textContent = 'EzChat Bán hàng: ' + text;
    };
    if (!/\/admin/.test(location.pathname)) {
        say('hãy mở trang quản trị Sapo (…mysapogo.com/admin/payment_vouchers) rồi bấm lại.');
        setTimeout(() => box.remove(), 10000);
        return;
    }
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    type Any = any;
    const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
    const get = async (url: string): Promise<Any> => {
        for (let attempt = 0; ; attempt++) {
            const response = await fetch(url, { credentials: 'include', headers: { accept: 'application/json' } });
            if (response.ok) return response.json();
            // Sapo answers 429 when read too fast: wait and try again a few times.
            if ((response.status === 429 || response.status >= 500) && attempt < 4) {
                await wait(2000 * (attempt + 1));
                continue;
            }
            throw new Error(url.split('?')[0] + ' trả về ' + response.status);
        }
    };
    /** Every page of a Sapo list, or [] when the shop's Sapo has no such list. */
    const all = async (path: string, key: string, label: string, optional: boolean): Promise<Any[]> => {
        const rows: Any[] = [];
        for (let page = 1; page < 2000; page++) {
            let data: Any;
            try {
                data = await get(path + (path.includes('?') ? '&' : '?') + 'limit=250&page=' + page);
            } catch (error) {
                if (optional && page === 1) return [];
                throw error;
            }
            const list: Any[] = data[key] || [];
            rows.push(...list);
            say('đang đọc ' + label + ': ' + rows.length + (data.metadata?.total ? '/' + data.metadata.total : '') + '…');
            if (list.length < 250) break;
        }
        return rows;
    };
    const num = (...values: Any[]): number => {
        for (const v of values) if (v !== null && v !== undefined && v !== '' && !isNaN(Number(v))) return Number(v);
        return 0;
    };
    const text = (...values: Any[]): string | null => {
        for (const v of values) if (typeof v === 'string' && v.trim() !== '') return v.trim();
        return null;
    };
    const bool = (...values: Any[]): boolean | null => {
        for (const v of values) if (typeof v === 'boolean') return v;
        return null;
    };
    const save = (name: string, body: Any) => {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([JSON.stringify(body)], { type: 'application/json' }));
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
    };
    const AUTO = /^(SON|PON|SRN|PRN|RON|RTN|SPN|POR)\d/i;

    try {
        const store = location.host.split('.')[0];
        const receipts = await all('/admin/receipt_vouchers.json', 'receipt_vouchers', 'phiếu thu', true);
        const payments = await all('/admin/payment_vouchers.json', 'payment_vouchers', 'phiếu chi', false);
        let skipped = 0;
        const vouchers: Any[] = [];
        const take = (v: Any, direction: string) => {
            const type = text(v.payment_voucher_type?.name, v.receipt_voucher_type?.name, v.voucher_type?.name, v.type_name, v.category_name, v.reason_name, typeof v.type === 'string' ? v.type : null);
            const ref = text(v.ref_code, v.reference_code, v.ref?.code, v.document_code, v.object_ref_code, v.order_code, v.purchase_order_code);
            const source = (text(v.source, v.created_source) || '').toLowerCase();
            const automatic = v.auto === true || v.is_auto === true || source === 'auto' || source === 'system' || (type || '').toLowerCase() === 'tự động' || (!!ref && AUTO.test(ref));
            if (automatic) {
                skipped++;
                return;
            }
            vouchers.push({
                id: v.id,
                code: text(v.code) || String(v.id),
                direction,
                status: text(v.status),
                type,
                auto: false,
                ref_code: ref,
                party_kind: text(v.object_type, v.subject_type, v.target_type, v.recipient_type, v.payer_type, v.group_name, v.object_group),
                party_name: text(v.object_name, v.subject_name, v.recipient_name, v.payer_name, v.target_name, v.object?.name),
                party_code: text(v.object_code, v.subject_code, v.object?.code),
                amount: num(v.amount, v.total_amount, v.value),
                method: text(v.payment_method_name, v.payment_method?.name, v.payment_method_code, v.method),
                paid_on: text(v.issued_on, v.paid_on, v.created_on),
                created_on: text(v.created_on),
                reference: text(v.reference, v.reference_number),
                note: text(v.note, v.description),
                counts_in_pnl: bool(v.accounting, v.business_result, v.counted_in_business_result, v.is_accounting),
            });
        };
        for (const v of receipts) take(v, 'receipt');
        for (const v of payments) take(v, 'payment');

        const name = 'sapo-so-quy-' + store + '.json';
        save(name, { format: 'ezsell-sapo-go-vouchers', version: 1, store: location.host, exported_at: new Date().toISOString(), skipped_auto: skipped, vouchers });
        say('đã lưu file ' + name + ' (' + vouchers.length + ' phiếu thu chi tự lập, bỏ ' + skipped + ' phiếu tự động của đơn hàng, nhập hàng) vào thư mục Tải xuống.\nQuay lại EzChat Bán hàng và tải file đó lên.');
        setTimeout(() => box.remove(), 60000);
    } catch (error) {
        say('chưa lấy được sổ quỹ (' + (error as Error).message + '). Hãy đăng nhập Sapo, mở trang Sổ quỹ rồi bấm lại.');
    }
}

/** The bookmark's address: the function's own source, run once. */
export function sapoGoVouchersBookmarklet(): string {
    return 'javascript:' + encodeURIComponent('(' + sapoGoVouchersExport.toString() + ')();void 0');
}
