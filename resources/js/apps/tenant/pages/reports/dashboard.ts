import { onMounted, ref, watch, type Ref } from 'vue';
import { ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';

/** Helpers shared by the report menu dashboards (mẫu 28/11). */

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** The period of the same length just before this one, to compare with. */
export function previousRange(from?: string, to?: string): { from: string; to: string } | null {
    if (!from || !to) return null;
    const day = 86_400_000;
    const f = new Date(`${from}T00:00:00`);
    const t = new Date(`${to}T00:00:00`);
    const len = Math.round((t.getTime() - f.getTime()) / day) + 1;
    if (!(len > 0)) return null;
    return { from: iso(new Date(f.getTime() - len * day)), to: iso(new Date(f.getTime() - day)) };
}

/** Change against the previous period in %, null when there is nothing to compare with. */
export function change(now: number | null | undefined, before: number | null | undefined): number | null {
    if (now == null || before == null || !Number(before)) return null;
    return ((Number(now) - Number(before)) / Math.abs(Number(before))) * 100;
}

/** "12,4%" (absolute value; the arrow says the direction). */
export const pct = (v: number) => `${Math.abs(v).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`;

/** Short money for the grey figure strips: "186,4 tr", "1,2 tỷ", "850.000". */
export function short(v: number | string | null | undefined): string {
    const n = Number(v ?? 0);
    const a = Math.abs(n);
    const f = (x: number) => x.toLocaleString('vi-VN', { maximumFractionDigits: 1 });
    if (a >= 1e9) return `${f(n / 1e9)} tỷ`;
    if (a >= 1e6) return `${f(n / 1e6)} tr`;
    return formatNumber(n);
}

/** Loads a dashboard's figures again whenever the period changes; keeps the last figures while reloading. */
export function useDashboard<T>(range: () => { from: string; to: string }, fetch: (r: { from: string; to: string }) => Promise<T>) {
    const data = ref<T | null>(null) as Ref<T | null>;
    const error = ref<string | null>(null);
    const loading = ref(false);
    async function load() {
        loading.value = true;
        error.value = null;
        try {
            data.value = await fetch(range());
        } catch (e) {
            error.value = (e as ApiError).message ?? 'Không tải được số liệu.';
        } finally {
            loading.value = false;
        }
    }
    onMounted(load);
    watch(() => [range().from, range().to], load);
    return { data, error, loading, load };
}

/** Avatar initials of a name: "Intel Gear Distribution" → "IG". */
export function initials(name: string): string {
    const words = name.replace(/[^\p{L}\p{N}\s]/gu, ' ').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return '?';
    return (words.length === 1 ? words[0]!.slice(0, 2) : words[0]![0]! + words[words.length - 1]![0]!).toUpperCase();
}
