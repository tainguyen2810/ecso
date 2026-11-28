/** Shared look of the Vận chuyển pages (mẫu Tailwind 28/11): cards, buttons, pills and status colours. */
import { cn } from '@/shared/lib/utils';

export const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
export const th = 'px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400';

export const btn = {
    primary: 'inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] bg-blue-600 px-4 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50',
    outline: 'inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] border border-slate-200 bg-white px-4 text-[15px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700 disabled:opacity-50',
    small: 'inline-flex h-9 items-center gap-1.5 whitespace-nowrap rounded-[10px] border border-slate-200 bg-white px-3 text-[13px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700 disabled:opacity-50',
    smallPrimary: 'inline-flex h-9 items-center gap-1.5 whitespace-nowrap rounded-[10px] bg-blue-600 px-3 text-[13px] font-medium text-white hover:bg-blue-700 disabled:opacity-50',
};

export const pill = (on: boolean) => cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-[9px] px-3 py-1.5 text-slate-500 hover:text-slate-900', on && 'bg-white font-semibold text-slate-900 shadow-sm');

export const iconTones: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-emerald-50 text-emerald-600',
    amber: 'bg-amber-50 text-amber-600',
    red: 'bg-red-50 text-red-500',
    violet: 'bg-violet-50 text-violet-600',
    slate: 'bg-slate-100 text-slate-600',
};

/** Chip colour of each shipment status. */
const statusTones: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-600',
    awaiting_pickup: 'bg-violet-50 text-violet-700',
    picked_up: 'bg-sky-50 text-sky-700',
    in_transit: 'bg-sky-50 text-sky-700',
    out_for_delivery: 'bg-blue-50 text-blue-700',
    redelivering: 'bg-amber-50 text-amber-700',
    delivered: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-red-50 text-red-600',
    returning: 'bg-amber-50 text-amber-700',
    returned: 'bg-orange-50 text-orange-700',
    cancelled: 'bg-slate-100 text-slate-500',
};
export const statusChip = (status: string) => cn('inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', statusTones[status] ?? statusTones.pending);

/** "86,4%" */
export const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')}%`);
