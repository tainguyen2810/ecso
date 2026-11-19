import { channelColor } from '@/shared/lib/tones';

/** One slice of "Doanh thu theo kênh bán": a marketplace, or one of the seller's own sales channels. */
export type RevenueChannel = { key: string; marketplace: string; channel: { name: string; type: string } | null; revenue: string };

/** Own channels get a colour by kind, so the shop counter is always green and a Fanpage always blue. */
const typeColors: Record<string, string> = {
    pos: 'var(--color-emerald-500)',
    store: 'var(--color-emerald-500)',
    facebook: 'var(--color-blue-500)',
    zalo: 'var(--color-cyan-500)',
    website: 'var(--color-violet-500)',
    phone: 'var(--color-amber-500)',
    other: 'var(--color-slate-400)',
};
// A second channel of the same kind (two Fanpages) takes the next free colour.
const spare = ['var(--color-teal-500)', 'var(--color-indigo-400)', 'var(--color-pink-400)', 'var(--color-lime-500)', 'var(--color-orange-400)', 'var(--color-sky-300)'];

export function channelColors(channels: RevenueChannel[]): Record<string, string> {
    const used = new Set<string>();
    const out: Record<string, string> = {};
    for (const c of channels) {
        let color = c.channel ? (typeColors[c.channel.type] ?? typeColors.other!) : channelColor(c.marketplace);
        if (c.channel && used.has(color)) color = spare.find((s) => !used.has(s)) ?? color;
        used.add(color);
        out[c.key] = color;
    }
    return out;
}

export function channelLabel(c: RevenueChannel, marketplaceNames: Record<string, string>): string {
    return c.channel?.name ?? marketplaceNames[c.marketplace] ?? c.marketplace;
}

export type StartStep = { key: string; label: string; detail: string; done: boolean; optional?: boolean; to: string | null; action: string };

/** Setup steps of "Bắt đầu"; the list is finished when every step that is not optional is done. */
export function startProgress(steps: StartStep[]): { done: number; total: number; finished: boolean } {
    return { done: steps.filter((s) => s.done).length, total: steps.length, finished: steps.every((s) => s.done || s.optional) };
}

export const MARKETPLACE_CHANNELS = ['shopee', 'tiktok', 'woocommerce', 'lazada_tiki'];

/**
 * A2: arranges "Bắt đầu" by the setup answers. A shop that sells on a marketplace connects it right after the
 * products (and must); a shop run by one person skips inviting staff. Without answers the list stays as it is.
 */
export function arrangeStartSteps(steps: StartStep[], channels: string[] | null | undefined, teamSize: string | null | undefined): StartStep[] {
    let out = teamSize === '1' ? steps.filter((s) => s.key !== 'members') : [...steps];
    if (channels?.some((c) => MARKETPLACE_CHANNELS.includes(c))) {
        const market = out.find((s) => s.key === 'marketplace');
        if (market) {
            out = out.filter((s) => s !== market);
            const at = out.findIndex((s) => s.key === 'products') + 1;
            out.splice(at, 0, { ...market, optional: false });
        }
    }
    return out;
}

/** Order statuses that mean the shop has handled an order all the way to packing or beyond. */
const HANDLED = ['PACKED', 'HANDED_OVER', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'];

export type SellerStartInput = {
    accounts: { id: string; shop_name: string; marketplace: string; status: string; stats?: { listings: number; mapped: number } }[];
    marketplaceNames: Record<string, string>;
    onHand: number;
    recentStatuses: string[];
    can: (permission: string) => boolean;
};

/**
 * Đợt 2: the 4 steps of a shop that sells on Shopee, TikTok or a website: connect a shop, link its products to the
 * stock, enter the opening stock, handle a first order. Each one ticks itself from what the shop already has.
 */
export function sellerStartSteps(i: SellerStartInput): StartStep[] {
    const live = i.accounts.filter((a) => a.status === 'connected');
    const first = live[0];
    const unlinkedOf = (a: SellerStartInput['accounts'][number]) => Math.max(0, (a.stats?.listings ?? 0) - (a.stats?.mapped ?? 0));
    const unlinked = live.reduce((s, a) => s + unlinkedOf(a), 0);
    const todoShop = live.find((a) => unlinkedOf(a) > 0);
    const name = (a: { marketplace: string; shop_name: string }) => `${i.marketplaceNames[a.marketplace] ?? a.marketplace} "${a.shop_name}"`;
    return [
        { key: 'shop', label: 'Kết nối shop', detail: first ? `Đã kết nối ${name(first)}${live.length > 1 ? ` và ${live.length - 1} shop khác` : ''}` : 'Kết nối Shopee, TikTok Shop hoặc website để đơn tự về đây', done: !!first, to: i.can('marketplace.connect') ? '/marketplaces' : null, action: 'Kết nối kênh bán' },
        { key: 'link', label: 'Liên kết sản phẩm', detail: !first ? 'Ghép sản phẩm trên sàn với sản phẩm trong kho' : unlinked ? `${unlinked} mã trên sàn chưa liên kết với sản phẩm trong kho` : 'Mọi mã trên sàn đã liên kết', done: !!first && unlinked === 0 && live.some((a) => (a.stats?.listings ?? 0) > 0), to: todoShop && i.can('marketplace.view') ? `/marketplaces/${todoShop.id}/listings` : null, action: 'Liên kết ngay' },
        { key: 'stock', label: 'Nhập tồn đầu kỳ', detail: 'Nhập số lượng đang có để đơn không báo "Thiếu hàng"', done: i.onHand > 0, to: first && i.can('marketplace.connect') ? `/marketplaces/${first.id}/setup` : i.can('inventory.adjust') ? '/inventory/stocktake/new' : null, action: 'Nhập tồn' },
        { key: 'order', label: 'Xử lý đơn đầu tiên', detail: 'Xác nhận, đóng gói và giao 1 đơn', done: i.recentStatuses.some((s) => HANDLED.includes(s)), to: i.can('orders.view') ? '/orders?tab=to_pack' : null, action: 'Xem đơn chờ xử lý' },
    ];
}
