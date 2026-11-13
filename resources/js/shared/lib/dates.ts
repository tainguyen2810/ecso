/**
 * Dates as Vietnamese users read and type them (dd/mm/yyyy, weeks from Monday). Values in the app stay ISO
 * yyyy-mm-dd local dates; only what is shown and typed changes.
 */

export const isoDate = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** yyyy-mm-dd → Date at local midnight (null for anything else). */
export function fromIso(iso: string | null | undefined): Date | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');
    if (!m) return null;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return d.getMonth() === Number(m[2]) - 1 ? d : null;
}

/** yyyy-mm-dd → dd/mm/yyyy ('' when empty or invalid). */
export function displayDate(iso: string | null | undefined): string {
    const d = fromIso(iso);
    return d ? `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}` : '';
}

/**
 * What a user typed → yyyy-mm-dd, or null when it is not a date. Understands 15/11/2026, 15/11/26, 15/11 (this
 * year), 151126, 15112026, 15-11-2026, 15.11.2026 and the ISO form 2026-11-15.
 */
export function parseDate(text: string, now = new Date()): string | null {
    const t = text.trim();
    if (!t) return null;
    if (/^\d{4}-\d{2}-\d{2}$/.test(t)) return fromIso(t) ? t : null;

    let day: number;
    let month: number;
    let year: number;
    const parts = t.split(/[/.\-\s]+/).filter(Boolean);
    if (parts.length >= 2 && parts.length <= 3 && parts.every((p) => /^\d+$/.test(p))) {
        day = Number(parts[0]);
        month = Number(parts[1]);
        year = parts[2] === undefined ? now.getFullYear() : Number(parts[2]);
        if (parts[2] !== undefined && parts[2].length <= 2) year += 2000;
        else if (parts[2] !== undefined && parts[2].length !== 4) return null;
    } else if (/^\d{6}$|^\d{8}$/.test(t)) {
        day = Number(t.slice(0, 2));
        month = Number(t.slice(2, 4));
        year = t.length === 6 ? 2000 + Number(t.slice(4)) : Number(t.slice(4));
    } else {
        return null;
    }
    const d = new Date(year, month - 1, day);
    if (d.getFullYear() !== year || d.getMonth() !== month - 1 || d.getDate() !== day) return null;
    return isoDate(d);
}

export const weekdayNames = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
const longWeekdays = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];

export function weekdayOf(iso: string | null | undefined): string {
    const d = fromIso(iso);
    return d ? longWeekdays[d.getDay()]! : '';
}

export type CalendarDay = { iso: string; day: number; inMonth: boolean; sunday: boolean };

/** The 6 weeks × 7 days shown for a month, Monday first. */
export function monthGrid(year: number, month: number): CalendarDay[] {
    const first = new Date(year, month, 1);
    const offset = (first.getDay() + 6) % 7;
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(year, month, 1 - offset + i);
        return { iso: isoDate(d), day: d.getDate(), inMonth: d.getMonth() === month, sunday: d.getDay() === 0 };
    });
}

export const addDays = (iso: string, days: number) => {
    const d = fromIso(iso) ?? new Date();
    return isoDate(new Date(d.getFullYear(), d.getMonth(), d.getDate() + days));
};

/** Whole days from one date to another, both included (1 for the same day). */
export function daysBetween(from: string, to: string): number {
    const a = fromIso(from);
    const b = fromIso(to);
    if (!a || !b) return 0;
    return Math.round((b.getTime() - a.getTime()) / 86_400_000) + 1;
}

export type RangePreset = 'today' | 'yesterday' | '7d' | '30d' | 'month' | 'last-month' | 'quarter' | 'year';

export const rangePresetLabels: Record<RangePreset, string> = {
    today: 'Hôm nay',
    yesterday: 'Hôm qua',
    '7d': '7 ngày qua',
    '30d': '30 ngày qua',
    month: 'Tháng này',
    'last-month': 'Tháng trước',
    quarter: 'Quý này',
    year: 'Năm nay',
};

export function rangeOf(preset: RangePreset, now = new Date()): { from: string; to: string } {
    const y = now.getFullYear();
    const m = now.getMonth();
    const d = now.getDate();
    const today = isoDate(now);
    switch (preset) {
        case 'today':
            return { from: today, to: today };
        case 'yesterday': {
            const y1 = isoDate(new Date(y, m, d - 1));
            return { from: y1, to: y1 };
        }
        case '7d':
            return { from: isoDate(new Date(y, m, d - 6)), to: today };
        case '30d':
            return { from: isoDate(new Date(y, m, d - 29)), to: today };
        case 'last-month':
            return { from: isoDate(new Date(y, m - 1, 1)), to: isoDate(new Date(y, m, 0)) };
        case 'quarter':
            return { from: isoDate(new Date(y, m - (m % 3), 1)), to: today };
        case 'year':
            return { from: isoDate(new Date(y, 0, 1)), to: today };
        default:
            return { from: isoDate(new Date(y, m, 1)), to: today };
    }
}

/** The preset that gives exactly this range today, if any (highlighted in the picker). */
export function presetOf(from: string, to: string, now = new Date()): RangePreset | null {
    return (Object.keys(rangePresetLabels) as RangePreset[]).find((p) => {
        const r = rangeOf(p, now);
        return r.from === from && r.to === to;
    }) ?? null;
}
