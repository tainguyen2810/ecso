/** Date range presets shared by the report pages (local dates, yyyy-mm-dd). */
export const isoDate = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export type Preset = 'today' | 'yesterday' | '7d' | 'month' | 'last-month' | '30d';

export function presetRange(preset: Preset, now = new Date()): { from: string; to: string } {
    const y = now.getFullYear();
    const m = now.getMonth();
    switch (preset) {
        case 'today':
            return { from: isoDate(now), to: isoDate(now) };
        case 'yesterday': {
            const d = isoDate(new Date(y, m, now.getDate() - 1));
            return { from: d, to: d };
        }
        case '7d':
            return { from: isoDate(new Date(y, m, now.getDate() - 6)), to: isoDate(now) };
        case '30d':
            return { from: isoDate(new Date(y, m, now.getDate() - 29)), to: isoDate(now) };
        case 'last-month':
            return { from: isoDate(new Date(y, m - 1, 1)), to: isoDate(new Date(y, m, 0)) };
        default:
            return { from: isoDate(new Date(y, m, 1)), to: isoDate(now) };
    }
}

export const presetLabels: Record<Preset, string> = {
    today: 'Hôm nay',
    yesterday: 'Hôm qua',
    '7d': '7 ngày',
    month: 'Tháng này',
    'last-month': 'Tháng trước',
    '30d': '30 ngày',
};

/**
 * CSV for spreadsheet export; a BOM keeps Vietnamese text readable in Excel. Text that Excel would run as a formula
 * (starting with = + - @, e.g. a customer named "=HYPERLINK(...)") gets a leading apostrophe; numbers stay numbers.
 */
export function toCsv(header: string[], rows: (string | number | null)[][]): string {
    const cell = (v: string | number | null) => {
        let s = v === null ? '' : String(v);
        if (typeof v === 'string' && /^[=+\-@\t\r]/.test(s) && !/^-?\d+(\.\d+)?$/.test(s)) s = `'${s}`;
        return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    return '﻿' + [header, ...rows].map((r) => r.map(cell).join(',')).join('\n');
}

export function downloadCsv(name: string, csv: string): void {
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.click();
    URL.revokeObjectURL(url);
}
