/**
 * The "Lấy dữ liệu Sapo" bookmark. Sapo GO gives shops no API key, so this runs inside the Sapo admin page the user
 * is signed in to: it reads the same product list the page loads (/admin/products.json, /admin/locations.json) and
 * saves it as a file the user uploads to EzChat Bán hàng (read by SapoGoReader). Nothing is sent anywhere else and EzChat Bán hàng
 * never sees the Sapo password.
 *
 * The function is turned into a bookmark with its own source, so it must not use anything from outside its body.
 */
export async function sapoGoExport(): Promise<void> {
    const box = document.createElement('div');
    box.setAttribute('style', 'position:fixed;z-index:2147483647;top:16px;right:16px;max-width:420px;background:#1e293b;color:#fff;padding:12px 16px;border-radius:8px;font:14px/1.5 sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3)');
    document.body.appendChild(box);
    const say = (text: string) => {
        box.textContent = 'EzChat Bán hàng: ' + text;
    };
    if (!/\/admin/.test(location.pathname)) {
        say('hãy mở trang quản trị Sapo (…mysapogo.com/admin/products) rồi bấm lại.');
        setTimeout(() => box.remove(), 10000);
        return;
    }
    try {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const get = async (url: string): Promise<any> => {
            const response = await fetch(url, { credentials: 'include', headers: { accept: 'application/json' } });
            if (!response.ok) throw new Error(url.split('?')[0] + ' trả về ' + response.status);
            return response.json();
        };
        say('đang đọc danh sách chi nhánh…');
        const locations = (await get('/admin/locations.json')).locations || [];
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const products: any[] = [];
        for (let page = 1; page < 400; page++) {
            const data = await get('/admin/products.json?limit=250&page=' + page);
            const list = data.products || [];
            products.push(...list);
            say('đã đọc ' + products.length + (data.metadata?.total ? '/' + data.metadata.total : '') + ' sản phẩm…');
            if (list.length < 250) break;
        }
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const picture = (image: any) => (image && (image.full_path || image.src)) || null;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const price = (variant: any, name: string, key: string) => {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const found = (variant.variant_prices || []).find((p: any) => p.name === name);
            return found ? found.value : variant[key];
        };
        const file = {
            format: 'ezsell-sapo-go',
            version: 1,
            store: location.host,
            exported_at: new Date().toISOString(),
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            locations: locations.map((l: any) => ({ id: l.id, name: l.label || l.name })),
            products: products.map((p) => ({
                id: p.id,
                name: p.name,
                status: p.status,
                description: p.description,
                category: p.category,
                brand: p.brand,
                tags: p.tags,
                product_type: p.product_type,
                images: (p.images || []).map(picture).filter(Boolean),
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                variants: (p.variants || []).map((v: any) => ({
                    sku: v.sku,
                    barcode: v.barcode,
                    opts: [v.opt1, v.opt2, v.opt3],
                    unit: v.unit,
                    status: v.status,
                    sellable: v.sellable,
                    retail: price(v, 'Giá bán lẻ', 'variant_retail_price'),
                    wholesale: price(v, 'Giá bán buôn', 'variant_whole_price'),
                    import: price(v, 'Giá nhập', 'variant_import_price'),
                    weight_value: v.weight_value,
                    weight_unit: v.weight_unit,
                    images: (Array.isArray(v.images) ? v.images : []).map(picture).filter(Boolean),
                    packsize: v.packsize,
                    packsize_quantity: v.packsize_quantity,
                    packsize_root_sku: v.packsize_root_sku,
                    // eslint-disable-next-line @typescript-eslint/no-explicit-any
                    composite_items: (v.composite_items || []).map((c: any) => ({ sku: c.sub_sku, quantity: c.quantity })),
                    // eslint-disable-next-line @typescript-eslint/no-explicit-any
                    stock: (v.inventories || []).map((i: any) => ({ location_id: i.location_id, on_hand: i.on_hand, mac: i.mac })),
                })),
            })),
        };
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([JSON.stringify(file)], { type: 'application/json' }));
        link.download = 'sapo-du-lieu-' + location.host.split('.')[0] + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        say('đã lưu file ' + link.download + ' (' + products.length + ' sản phẩm). Quay lại EzChat Bán hàng và tải file này lên.');
        setTimeout(() => box.remove(), 20000);
    } catch (error) {
        say('chưa lấy được dữ liệu (' + (error as Error).message + '). Hãy đăng nhập Sapo, mở trang Sản phẩm rồi bấm lại.');
    }
}

/** The bookmark's address: the function's own source, run once. */
export function sapoGoBookmarklet(): string {
    return 'javascript:' + encodeURIComponent('(' + sapoGoExport.toString() + ')();void 0');
}
