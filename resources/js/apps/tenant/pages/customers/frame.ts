import { ref } from 'vue';
import { cn } from '@/shared/lib/utils';

/** Numbers on the Khách hàng tabs, filled by the pages that know them (running promotions, duplicate groups). */
export const runningPromotions = ref<number | null>(null);
export const duplicateGroups = ref<number | null>(null);

/** Card of the Khách hàng pages (mẫu 28/11). */
export const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';

/** One option of a segmented control (Tất cả | Đang giao dịch | …). */
export const pill = (on: boolean) => cn('whitespace-nowrap rounded-[9px] px-3.5 py-1.5', on ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800');

/** Buttons of the page head. */
export const btnPrimary = 'inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-60';
export const btnOutline = 'inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] border border-slate-200 bg-white px-4 text-[15px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700 disabled:opacity-60';

/** Initials for the round avatar of a customer ("Công ty TNHH An Phát" → "AP"). */
export function initials(name: string): string {
    const words = name.split(/\s+/).map((w) => w.replace(/[^\p{L}\p{N}]/gu, '')).filter(Boolean);
    return words.map((w) => w[0]).join('').slice(-2).toUpperCase() || '?';
}
const avatarTones = ['bg-blue-100 text-blue-700', 'bg-violet-100 text-violet-700', 'bg-amber-100 text-amber-700', 'bg-emerald-100 text-emerald-700', 'bg-pink-100 text-pink-700', 'bg-slate-100 text-slate-600'];
/** Same colour for the same name everywhere. */
export function avatarTone(name: string): string {
    let h = 0;
    for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return avatarTones[h % avatarTones.length]!;
}
