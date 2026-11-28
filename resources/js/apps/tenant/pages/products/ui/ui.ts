/** Shared look of the Sản phẩm pages (mẫu Tailwind 28/11). */
export const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
export const th = 'whitespace-nowrap px-4 py-3 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400';
export const thead = 'bg-slate-50/70';
export const row = 'border-t border-slate-100 hover:bg-slate-50/70';
export const rowButton = 'inline-flex h-8 items-center whitespace-nowrap rounded-[8px] border border-slate-200 bg-white px-3 text-[13px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700';
export const rowPrimary = 'inline-flex h-8 items-center whitespace-nowrap rounded-[8px] bg-blue-600 px-3 text-[13px] font-medium text-white hover:bg-blue-700';

export type ChipTone = 'green' | 'amber' | 'blue' | 'slate' | 'red' | 'violet';
export const chip: Record<ChipTone, string> = {
    green: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    blue: 'bg-blue-50 text-blue-700',
    slate: 'bg-slate-100 text-slate-600',
    red: 'bg-red-50 text-red-600',
    violet: 'bg-violet-50 text-violet-700',
};
export const chipBase = 'inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium';

/** "2 chữ cái đầu" avatar of a supplier or person. */
export function initials(name: string): string {
    return name.trim().split(/\s+/).slice(-2).map((w) => w[0] ?? '').join('').toUpperCase();
}

/** Short money for side cards: 15.300.000 → "15,3tr". */
export function shortMoney(v: number | string): string {
    const n = Number(v);
    if (Math.abs(n) >= 1_000_000) return `${(n / 1_000_000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}tr`;
    if (Math.abs(n) >= 1_000) return `${Math.round(n / 1_000).toLocaleString('vi-VN')}k`;
    return n.toLocaleString('vi-VN');
}
