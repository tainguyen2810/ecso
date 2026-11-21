/** "Tạo sản phẩm từ ảnh": AI suggestions and the product they become. */
export interface ProductSuggestion {
    images: number[];
    name: string;
    category: { read: string; id: string | null; path: string | null };
    brand: { read: string; id: string | null };
    sku: string;
    warranty_months: number | null;
    weight_gram: number | null;
    cost_price: number | null;
    price: number | null;
    variants: { name: string; values: string[] }[];
    description: string;
    seen: string;
    missing: string;
    duplicate: { product_id: string | null; name: string; sku: string } | null;
}

export interface SuggestionRow extends ProductSuggestion {
    include: boolean;
    open: boolean;
    saved: string | null;
    error: string | null;
}

/** Every combination of the option values: Màu × Size → [{Màu: Đen, Size: M}, …]. */
export function combinations(variants: { name: string; values: string[] }[]): Record<string, string>[] {
    let out: Record<string, string>[] = [{}];
    for (const v of variants) {
        const values = v.values.filter((x) => x.trim());
        if (!v.name.trim() || !values.length) continue;
        out = out.flatMap((o) => values.map((val) => ({ ...o, [v.name.trim()]: val.trim() })));
    }
    return out.slice(0, 200);
}

const slug = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').replace(/[^A-Za-z0-9]+/g, '').toUpperCase().slice(0, 8) || 'X';

/** Body for POST /products: one variant per option combination, SKU = base + option codes, same price. */
export function productBody(s: ProductSuggestion) {
    const combos = combinations(s.variants);
    const single = combos.length === 1 && Object.keys(combos[0]!).length === 0;
    const warranty = s.warranty_months ? `\n\nBảo hành: ${s.warranty_months} tháng.` : '';
    return {
        name: s.name.trim(),
        description: (s.description.trim() + warranty).trim() || null,
        category_id: s.category.id,
        brand_id: s.brand.id,
        variants: combos.map((options) => ({
            sku: single ? s.sku : `${s.sku}-${Object.values(options).map(slug).join('-')}`.slice(0, 64),
            name: single ? null : Object.values(options).join(' / '),
            options: single ? null : options,
            ref_price: s.price ?? null,
            weight_gram: s.weight_gram ?? null,
        })),
    };
}

export function rows(list: ProductSuggestion[]): SuggestionRow[] {
    return list.map((s, i) => ({ ...s, include: !s.duplicate, open: list.length === 1 || i === 0, saved: null, error: null }));
}
