/**
 * The "Lấy nhập hàng Sapo" bookmark: like "Lấy đơn hàng Sapo" (sapoGoOrdersExport.ts) it runs inside the Sapo GO admin
 * page the user is signed in to and reads the lists that page itself loads: /admin/suppliers.json,
 * /admin/purchase_orders.json (Nhập hàng) and /admin/supplier_returns.json (Trả hàng nhà cung cấp). It saves them as
 * files of at most ORDERS_PER_FILE purchase orders, which the user uploads to EzChat Bán hàng (read by
 * SapoPurchaseImporter). Nothing is sent anywhere else and the Sapo password is never needed.
 *
 * Sapo's own field names live only here: several names are tried for each value because Sapo GO's internal API is not
 * documented. The function becomes a bookmark with its own source, so it must not use anything from outside its body.
 */
export async function sapoGoPurchasesExport(): Promise<void> {
    const ORDERS_PER_FILE = 2000;
    const box = document.createElement('div');
    box.setAttribute('style', 'position:fixed;z-index:2147483647;top:16px;right:16px;max-width:440px;background:#1e293b;color:#fff;padding:12px 16px;border-radius:8px;font:14px/1.5 sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3);white-space:pre-line');
    document.body.appendChild(box);
    const say = (text: string) => {
        box.textContent = 'EzChat Bán hàng: ' + text;
    };
    if (!/\/admin/.test(location.pathname)) {
        say('hãy mở trang quản trị Sapo (…mysapogo.com/admin/purchase_orders) rồi bấm lại.');
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
    const names = (list: Any[]) => {
        const map: Record<string, string> = {};
        for (const item of list) map[String(item.id)] = text(item.name, item.label, item.full_name, item.email) || String(item.id);
        return map;
    };
    const save = (name: string, body: Any) => {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([JSON.stringify(body)], { type: 'application/json' }));
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
    };
    const address = (a: Any) => (!a ? null : typeof a === 'string' ? text(a) : text(a.full_address, [a.address1, a.address2, a.ward, a.district, a.city].filter(Boolean).join(', ')));
    const money = (list: Any) => (Array.isArray(list) ? list : []).map((p: Any) => ({
        amount: num(p.amount, p.paid_amount, p.value),
        method: text(p.payment_method_name, p.payment_method?.name, p.method_name, p.method, p.payment_method_code),
        paid_on: text(p.paid_on, p.processed_on, p.created_on),
        reference: text(p.reference, p.code),
    }));

    try {
        say('đang đọc chi nhánh, nhân viên…');
        const store = location.host.split('.')[0];
        const locations = names((await get('/admin/locations.json').catch(() => ({ locations: [] }))).locations || []);
        const accounts = names(await all('/admin/accounts.json', 'accounts', 'nhân viên', true));
        const suppliersRaw = await all('/admin/suppliers.json', 'suppliers', 'nhà cung cấp', true);
        const orders = await all('/admin/purchase_orders.json', 'purchase_orders', 'đơn nhập hàng', false);
        // A list row without its products: read that order alone.
        for (let i = 0; i < orders.length; i++) {
            if (!(orders[i].line_items || orders[i].purchase_order_line_items)) {
                say('đang đọc chi tiết đơn nhập ' + (i + 1) + '/' + orders.length + '…');
                const one = await get('/admin/purchase_orders/' + orders[i].id + '.json').catch(() => null);
                if (one?.purchase_order) orders[i] = one.purchase_order;
            }
        }
        const returns = await all('/admin/supplier_returns.json', 'supplier_returns', 'đơn trả hàng nhà cung cấp', true);

        const suppliers: Record<string, Any> = {};
        const supplier = (s: Any) => ({
            id: s.id || null,
            code: text(s.code),
            name: text(s.name, s.full_name),
            phone: text(s.phone_number, s.phone, s.addresses?.[0]?.phone_number),
            email: text(s.email),
            tax_code: text(s.tax_number, s.tax_code),
            address: address(s.addresses?.[0] || s.address),
            contact: text(s.contact_name, s.addresses?.[0]?.full_name, s.addresses?.[0]?.first_name),
            note: text(s.description, s.note),
            status: text(s.status),
            debt: num(s.debt),
        });
        for (const s of suppliersRaw) suppliers[String(s.id)] = supplier(s);
        const supplierOf = (o: Any) => {
            const known = suppliers[String(o.supplier_id || o.supplier_data?.id || o.supplier?.id)];
            if (known) return { id: known.id, code: known.code, name: known.name };
            const s = o.supplier_data || o.supplier || {};
            return { id: s.id || o.supplier_id || null, code: text(s.code, o.supplier_code), name: text(s.name, o.supplier_name) };
        };
        const codes: Record<string, string> = {};
        for (const o of orders) codes[String(o.id)] = text(o.code) || String(o.id);

        const compact = (o: Any) => {
            const receipts = o.receipts || o.receive_inventories || [];
            return {
                id: o.id,
                code: text(o.code) || String(o.id),
                reference: text(o.reference_number, o.reference),
                status: text(o.status),
                receive_status: text(o.receive_status, o.received_status, o.receipt_status, o.stock_in_status),
                payment_status: text(o.payment_status),
                created_on: text(o.issued_on, o.created_on),
                received_on: text(o.received_on, o.receipted_on, o.stocked_on, receipts[0]?.created_on),
                completed_on: text(o.completed_on),
                cancelled_on: text(o.cancelled_on),
                due_on: text(o.due_on, o.due_date),
                location: locations[String(o.location_id)] || null,
                staff: accounts[String(o.assignee_id || o.account_id)] || null,
                supplier: supplierOf(o),
                note: text(o.note),
                tags: Array.isArray(o.tags) ? o.tags : typeof o.tags === 'string' && o.tags ? o.tags.split(',') : [],
                total: num(o.total, o.total_price, o.grand_total),
                discount: num(o.discount_amount, o.total_discount, o.order_discount_amount),
                extra_cost: num(o.total_landed_cost_amount, o.landed_cost_amount, (o.landed_costs || []).reduce((s: number, c: Any) => s + num(c.amount, c.value), 0)),
                tax: num(o.total_tax, o.tax_amount),
                paid: num(o.paid_amount, o.total_paid, o.paid),
                payments: money(o.payments || o.prepayments || o.transactions),
                lines: (o.line_items || o.purchase_order_line_items || []).map((l: Any) => ({
                    sku: text(l.sku),
                    barcode: text(l.barcode),
                    name: text(l.product_name, l.name, l.variant_name) || 'Sản phẩm',
                    unit: text(l.unit),
                    quantity: num(l.quantity),
                    received: num(l.received_quantity, l.receipted_quantity, l.quantity_received, l.quantity),
                    price: num(l.price),
                    discount: num(l.discount_amount, l.discount_value),
                    tax_rate: num(l.tax_rate, l.tax?.rate, l.tax_rate_override),
                })),
            };
        };
        const compactReturn = (r: Any) => ({
            id: r.id,
            code: text(r.code) || String(r.id),
            purchase_code: text(r.purchase_order_code, r.purchase_order?.code) || codes[String(r.purchase_order_id)] || null,
            supplier: supplierOf(r),
            location: locations[String(r.location_id)] || null,
            status: text(r.status),
            created_on: text(r.created_on),
            reason: text(r.reason, r.note),
            total: num(r.total_amount, r.total_price, r.total),
            refunded: num(r.refunded_amount, r.total_refunded, r.refund_amount, r.paid_amount),
            refunds: money(r.refunds || r.transactions || r.payments),
            lines: (r.line_items || r.supplier_return_line_items || []).map((l: Any) => ({ sku: text(l.sku), barcode: text(l.barcode), quantity: num(l.quantity), price: num(l.price) })),
        });

        const files = Math.max(1, Math.ceil(orders.length / ORDERS_PER_FILE));
        const saved: string[] = [];
        for (let part = 0; part < files; part++) {
            const name = 'sapo-nhap-hang-' + store + (files > 1 ? '-' + String(part + 1).padStart(2, '0') : '') + '.json';
            save(name, {
                format: 'ezsell-sapo-go-purchases',
                version: 1,
                store: location.host,
                exported_at: new Date().toISOString(),
                part: part + 1,
                parts: files,
                suppliers: part === 0 ? Object.values(suppliers) : [],
                purchases: orders.slice(part * ORDERS_PER_FILE, (part + 1) * ORDERS_PER_FILE).map(compact),
                // Returns go with the last file, once every order they belong to has been read.
                returns: part === files - 1 ? returns.map(compactReturn) : [],
            });
            saved.push(name);
            await wait(400);
        }
        say('đã lưu ' + saved.length + ' file (' + suppliersRaw.length + ' nhà cung cấp, ' + orders.length + ' đơn nhập, ' + returns.length + ' đơn trả NCC) vào thư mục Tải xuống.\nQuay lại EzChat Bán hàng và tải file sapo-nhap-hang-' + store + '….json lên.');
        setTimeout(() => box.remove(), 60000);
    } catch (error) {
        say('chưa lấy được dữ liệu nhập hàng (' + (error as Error).message + '). Hãy đăng nhập Sapo, mở trang Nhập hàng rồi bấm lại.');
    }
}

/** The bookmark's address: the function's own source, run once. */
export function sapoGoPurchasesBookmarklet(): string {
    return 'javascript:' + encodeURIComponent('(' + sapoGoPurchasesExport.toString() + ')();void 0');
}
