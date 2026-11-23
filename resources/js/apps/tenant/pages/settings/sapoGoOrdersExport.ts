/**
 * The "Lấy đơn hàng Sapo" bookmark: like "Lấy dữ liệu Sapo" (sapoGoExport.ts) it runs inside the Sapo GO admin page the
 * user is signed in to and reads the lists that page itself loads (/admin/orders.json, /admin/order_returns.json…). It
 * saves the order history as files of at most ORDERS_PER_FILE orders each, which the user uploads to EzChat Bán hàng
 * (read by SapoOrderReader). Nothing is sent anywhere else and the Sapo password is never needed.
 *
 * Sapo's own field names live only here: the file keeps a small format of its own, so a change on Sapo's side is fixed
 * in this one function. Several names are tried for each value because Sapo GO's internal API is not documented.
 *
 * The function becomes a bookmark with its own source, so it must not use anything from outside its body.
 */
export async function sapoGoOrdersExport(): Promise<void> {
    const ORDERS_PER_FILE = 2000;
    const box = document.createElement('div');
    box.setAttribute('style', 'position:fixed;z-index:2147483647;top:16px;right:16px;max-width:440px;background:#1e293b;color:#fff;padding:12px 16px;border-radius:8px;font:14px/1.5 sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3);white-space:pre-line');
    document.body.appendChild(box);
    const say = (text: string) => {
        box.textContent = 'EzChat Bán hàng: ' + text;
    };
    if (!/\/admin/.test(location.pathname)) {
        say('hãy mở trang quản trị Sapo (…mysapogo.com/admin/orders) rồi bấm lại.');
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

    try {
        say('đang đọc chi nhánh, nguồn đơn, nhân viên…');
        const store = location.host.split('.')[0];
        const locations = names((await get('/admin/locations.json').catch(() => ({ locations: [] }))).locations || []);
        const sources = names(await all('/admin/sources.json', 'sources', 'nguồn đơn', true));
        const accounts = names(await all('/admin/accounts.json', 'accounts', 'nhân viên', true));
        const orders = await all('/admin/orders.json', 'orders', 'đơn hàng', false);
        const returns = await all('/admin/order_returns.json', 'order_returns', 'đơn trả hàng', true);

        const address = (a: Any) => (a ? text(a.full_address, [a.address1, a.address2, a.ward, a.district, a.city].filter(Boolean).join(', ')) : null);
        const compact = (o: Any) => {
            const customer = o.customer_data || o.customer || {};
            const shipping = o.shipping_address || o.billing_address || null;
            const fulfillment = (o.fulfillments || []).find((f: Any) => f && f.shipment) || (o.fulfillments || [])[0] || null;
            const shipment = fulfillment?.shipment || {};
            return {
                id: o.id,
                code: text(o.code, o.name) || String(o.id),
                reference: text(o.reference_number, o.reference),
                status: o.status,
                fulfillment_status: text(o.fulfillment_status, o.packed_status),
                payment_status: o.payment_status,
                return_status: o.return_status,
                created_on: text(o.issued_on, o.created_on),
                finalized_on: text(o.finalized_on),
                completed_on: text(o.completed_on),
                cancelled_on: text(o.cancelled_on),
                delivered_on: text(fulfillment?.delivered_on, fulfillment?.received_on, shipment.delivered_on),
                source: sources[String(o.source_id)] || text(o.source_name, o.channel),
                location: locations[String(o.location_id)] || null,
                staff: accounts[String(o.assignee_id || o.account_id)] || null,
                customer: {
                    id: customer.id || o.customer_id || null,
                    code: text(customer.code),
                    name: text(customer.name, shipping?.full_name, shipping?.name),
                    phone: text(customer.phone_number, customer.phone, shipping?.phone_number, shipping?.phone),
                    email: text(customer.email),
                },
                address: address(shipping),
                note: text(o.note),
                tags: Array.isArray(o.tags) ? o.tags : typeof o.tags === 'string' && o.tags ? o.tags.split(',') : [],
                total: num(o.total, o.total_price),
                discount: num(o.order_discount_amount, o.discount_amount),
                shipping_fee: num(o.delivery_fee?.fee, o.delivery_fee, o.shipping_fee),
                tax: num(o.total_tax),
                paid: (o.prepayments || []).reduce((sum: number, p: Any) => sum + num(p.paid_amount, p.amount), 0) || num(o.paid_amount, o.total_paid),
                cod: num(shipment.cod_amount, fulfillment?.cod_amount),
                carrier: text(shipment.delivery_service_provider?.name, shipment.service_name, fulfillment?.delivery_service_provider_name),
                tracking: text(shipment.tracking_code, fulfillment?.tracking_code),
                lines: (o.order_line_items || o.line_items || []).map((l: Any) => ({
                    id: l.id,
                    sku: text(l.sku),
                    barcode: text(l.barcode),
                    name: text(l.product_name, l.name, l.variant_name) || 'Sản phẩm',
                    variant: text(l.variant_name, l.variant_options),
                    unit: text(l.unit),
                    quantity: num(l.quantity),
                    price: num(l.price),
                    discount: num(l.discount_amount),
                    cost: l.cost_price ?? l.mac ?? null,
                    service: !l.variant_id && !l.sku,
                })),
            };
        };

        const files = Math.max(1, Math.ceil(orders.length / ORDERS_PER_FILE));
        const saved: string[] = [];
        for (let part = 0; part < files; part++) {
            const slice = orders.slice(part * ORDERS_PER_FILE, (part + 1) * ORDERS_PER_FILE);
            const name = 'sapo-don-hang-' + store + '-' + String(part + 1).padStart(2, '0') + '.json';
            save(name, {
                format: 'ezsell-sapo-go-orders',
                version: 1,
                store: location.host,
                exported_at: new Date().toISOString(),
                part: part + 1,
                parts: files,
                orders: slice.map(compact),
                // Returns go with the last file, once every order they belong to has been read.
                returns: part === files - 1 ? returns.map((r: Any) => ({
                    id: r.id,
                    code: text(r.code) || String(r.id),
                    order_id: r.order_id,
                    order_code: text(r.order_code),
                    status: r.status,
                    created_on: text(r.created_on),
                    received_on: text(r.received_on, r.completed_on),
                    reason: text(r.reason, r.note),
                    refund: num(r.total_amount, r.refund_amount, r.total),
                    lines: (r.line_items || r.order_return_line_items || []).map((l: Any) => ({ sku: text(l.sku), line_id: l.order_line_item_id || null, quantity: num(l.quantity), price: num(l.price) })),
                })) : [],
            });
            saved.push(name);
            await wait(400);
        }
        say('đã lưu ' + saved.length + ' file (' + orders.length + ' đơn, ' + returns.length + ' đơn trả) vào thư mục Tải xuống.\nQuay lại EzChat Bán hàng, chọn tất cả các file sapo-don-hang-' + store + '-….json và tải lên.');
        setTimeout(() => box.remove(), 60000);
    } catch (error) {
        say('chưa lấy được đơn hàng (' + (error as Error).message + '). Hãy đăng nhập Sapo, mở trang Đơn hàng rồi bấm lại.');
    }
}

/** The bookmark's address: the function's own source, run once. */
export function sapoGoOrdersBookmarklet(): string {
    return 'javascript:' + encodeURIComponent('(' + sapoGoOrdersExport.toString() + ')();void 0');
}
