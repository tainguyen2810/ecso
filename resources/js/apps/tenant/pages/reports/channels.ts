/**
 * Sales channels in reports (mẫu 28/11 b): grouped as Sàn TMĐT, Website, Chat, Tại cửa hàng; each shop or site one row
 * with the channel's real logo, the shop's own name and the channel's name under it (two shops with the same name on
 * Shopee and TikTok are told apart).
 */
export type ChannelGroupKey = 'market' | 'website' | 'chat' | 'store' | 'other';

export const channelGroupLabels: Record<ChannelGroupKey, string> = {
    market: 'Sàn TMĐT',
    website: 'Website',
    chat: 'Chat',
    store: 'Tại cửa hàng',
    other: 'Kênh khác',
};
const order: ChannelGroupKey[] = ['market', 'website', 'chat', 'store', 'other'];

const groupOf: Record<string, ChannelGroupKey> = {
    shopee: 'market',
    tiktok: 'market',
    lazada: 'market',
    tiki: 'market',
    woocommerce: 'website',
    website: 'website',
    zalo: 'chat',
    facebook: 'chat',
    pos: 'store',
    store: 'store',
    manual: 'store',
};

/** The channel's own name, shown under the shop name. */
export const channelNames: Record<string, string> = {
    shopee: 'Shopee',
    tiktok: 'TikTok Shop',
    lazada: 'Lazada',
    tiki: 'Tiki',
    woocommerce: 'WooCommerce',
    website: 'Website',
    zalo: 'Zalo',
    facebook: 'Facebook',
    pos: 'Bán tại quầy',
    store: 'Cửa hàng',
    manual: 'Bán tại cửa hàng',
    phone: 'Điện thoại',
    other: 'Khác',
};

export const channelGroup = (kind?: string | null): ChannelGroupKey => groupOf[kind ?? ''] ?? 'other';
export const channelName = (kind?: string | null): string => channelNames[kind ?? ''] ?? 'Khác';

/** Row label: the shop / site name, or the channel's name when the row has none ("Khác"). */
export function channelLabel(label: string | null | undefined, kind?: string | null): string {
    return !label || label === 'Khác' ? channelName(kind) : label;
}

export type ChannelGroup<T> = { key: ChannelGroupKey; label: string; rows: T[] };

/** Rows in channel groups, groups in a fixed order; rows keep the order given (e.g. by revenue). */
export function groupChannels<T>(rows: T[], kind: (r: T) => string | null | undefined): ChannelGroup<T>[] {
    const by = new Map<ChannelGroupKey, T[]>();
    for (const r of rows) {
        const g = channelGroup(kind(r));
        by.set(g, [...(by.get(g) ?? []), r]);
    }
    return order.filter((g) => by.has(g)).map((g) => ({ key: g, label: channelGroupLabels[g], rows: by.get(g)! }));
}
