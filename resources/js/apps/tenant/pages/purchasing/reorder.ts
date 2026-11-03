/** Hand-off from the reorder suggestions page to a new purchase order (sessionStorage). */
export const REORDER_DRAFT_KEY = 'ecso.purchasing.reorder-draft';

export interface ReorderDraft {
    warehouse_id: string;
    supplier_id: string;
    lines: { id: string; sku: string; name: string; stock: number | null; quantity: number; unit_cost: string }[];
}

export interface ReorderRow {
    variant: { id: string; sku: string; name: string };
    sold: number;
    daily: number;
    available: number;
    safety_stock: number;
    on_order: number;
    suggested: number;
    unit_cost: string;
    supplier: { id: string; name: string } | null;
}

/** Builds a draft from the ticked rows; the supplier is pre-selected when all of them came from the same one. */
export function draftFromRows(rows: ReorderRow[], warehouseId: string, quantities: Record<string, number>): ReorderDraft {
    const suppliers = new Set(rows.map((r) => r.supplier?.id ?? ''));
    return {
        warehouse_id: warehouseId,
        supplier_id: suppliers.size === 1 ? [...suppliers][0]! : '',
        lines: rows.map((r) => ({ id: r.variant.id, sku: r.variant.sku, name: r.variant.name, stock: r.available, quantity: quantities[r.variant.id] ?? r.suggested, unit_cost: r.unit_cost })),
    };
}
