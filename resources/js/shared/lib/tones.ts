/**
 * Colour tones shared by stat tiles, icon bubbles and highlight boxes (docs/DESIGN-SYSTEM.md).
 * Light Tailwind tints for backgrounds (50), 500 for icons, 600 for numbers. Use colour only when it carries meaning:
 * blue = money/neutral figure, green = good/new, amber = needs attention, red = problem, slate = plain count.
 */
export type Tone = 'blue' | 'green' | 'amber' | 'red' | 'slate';

export const toneClasses: Record<Tone, { bubble: string; value: string; soft: string }> = {
    blue: { bubble: 'bg-blue-50 text-blue-500', value: 'text-blue-600', soft: 'bg-blue-50/70' },
    green: { bubble: 'bg-emerald-50 text-emerald-500', value: 'text-emerald-600', soft: 'bg-emerald-50/70' },
    amber: { bubble: 'bg-amber-50 text-amber-500', value: 'text-amber-600', soft: 'bg-amber-50/70' },
    red: { bubble: 'bg-rose-50 text-rose-500', value: 'text-rose-600', soft: 'bg-rose-50/70' },
    slate: { bubble: 'bg-slate-100 text-slate-500', value: 'text-foreground', soft: 'bg-slate-50' },
};

/** Chart colour of a sales channel (orders.marketplace); tokens live in resources/css/app.css. */
export const channelColors: Record<string, string> = {
    shopee: 'var(--color-channel-shopee)',
    tiktok: 'var(--color-channel-tiktok)',
    lazada: 'var(--color-channel-lazada)',
    tiki: 'var(--color-channel-tiki)',
    woocommerce: 'var(--color-channel-woocommerce)',
    manual: 'var(--color-channel-manual)',
};

export function channelColor(key: string): string {
    return channelColors[key] ?? 'var(--color-channel-other)';
}
