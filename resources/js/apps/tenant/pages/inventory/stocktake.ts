export interface CountRow {
    id: string;
    sku: string;
    barcode: string | null;
    name: string;
    on_hand: number;
}

type Counts = Record<string, number | ''>;

/** Counted minus system quantity, or null when the SKU has not been counted. */
export function variance(row: CountRow, counts: Counts): number | null {
    const c = counts[row.id];
    return c === undefined || c === '' ? null : Number(c) - row.on_hand;
}

/** A scan adds one to the matching SKU (by barcode, then SKU, case-insensitive). Returns the SKU's id. */
export function applyScan(rows: CountRow[], counts: Counts, code: string): string | null {
    const lower = code.toLowerCase();
    const row = rows.find((r) => r.barcode === code) ?? rows.find((r) => r.sku.toLowerCase() === lower);
    if (!row) return null;
    const current = counts[row.id];
    counts[row.id] = (current === undefined || current === '' ? 0 : Number(current)) + 1;
    return row.id;
}

/** Adjustment lines in count mode: every counted SKU, plus uncounted ones as 0 when the whole warehouse is counted. */
export function stocktakeLines(rows: CountRow[], counts: Counts, zeroUncounted: boolean): { variant_id: string; mode: 'count'; quantity: number }[] {
    return rows.flatMap((r) => {
        const c = counts[r.id];
        if (c === undefined || c === '') {
            return zeroUncounted && r.on_hand !== 0 ? [{ variant_id: r.id, mode: 'count' as const, quantity: 0 }] : [];
        }
        return [{ variant_id: r.id, mode: 'count' as const, quantity: Math.max(0, Math.floor(Number(c))) }];
    });
}
