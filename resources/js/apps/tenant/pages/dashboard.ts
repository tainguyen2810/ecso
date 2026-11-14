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
