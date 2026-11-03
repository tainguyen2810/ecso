import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isoDate, presetRange } from './range';

/**
 * Report filters live in the URL (?from=&to=&by=...), so a report can be bookmarked or shared and the report
 * centre can open a detail report on the same period it shows.
 */
export function useReportQuery() {
    const route = useRoute();
    const router = useRouter();
    const set = (patch: Record<string, string | undefined>) => router.replace({ query: { ...route.query, ...patch } });
    const str = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');

    const fallback = presetRange('month');
    const range = computed({
        get: () => ({ from: str('from') || fallback.from, to: str('to') || fallback.to }),
        set: (r: { from: string; to: string }) => set({ from: r.from, to: r.to }),
    });
    const param = (k: string, initial = '') =>
        computed({
            get: () => str(k) || initial,
            set: (v: string) => set({ [k]: v || undefined }),
        });

    return { range, param, today: isoDate(new Date()) };
}
