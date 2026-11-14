/** A5: types and labels of the setup steps after a shop is connected for the first time. */
export type StockSource = 'marketplace' | 'ezsell' | 'none';
export type SetupAction = 'link' | 'create' | 'create_generated' | 'later';

export interface ShopSetupOverview {
    pending: boolean;
    listings_synced: boolean;
    last_synced_at: string | null;
    counts: { total: number; matched: number; create: number; create_without_sku: number; later: number; unlinked_left: number };
    preview: { title: string; seller_sku: string | null; internal: string | null; action: SetupAction }[];
    stock: {
        suggested: 'ezsell' | 'marketplace';
        linked: number;
        from_shop_lines: number;
        from_shop_quantity: number;
        ezsell_quantity: number;
        rows: { sku: string; marketplace: number; on_hand: number }[];
    };
}

export type ActionTone = 'success' | 'info' | 'muted';

/** What step 2 does with one shop SKU; listings without a seller SKU are created only when the user ticks that box. */
export function setupActionLabel(action: SetupAction, withoutSku: boolean): { label: string; tone: ActionTone } {
    switch (action) {
        case 'link':
            return { label: 'Ghép', tone: 'success' };
        case 'create':
            return { label: 'Tạo mới', tone: 'info' };
        case 'create_generated':
            return withoutSku ? { label: 'Tạo mới (mã theo ID)', tone: 'info' } : { label: 'Để sau', tone: 'muted' };
        default:
            return { label: 'Để sau', tone: 'muted' };
    }
}
