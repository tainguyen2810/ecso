<script setup lang="ts">
import { computed } from 'vue';
import { ArrowRight } from 'lucide-vue-next';
import type { RouteLocationRaw } from 'vue-router';
import { cn } from '@/shared/lib/utils';
import type { ReportLink } from './groups';

/** One figure of a card: `value` may carry its unit ("53,5 tr", "86 ngày"); the unit is shown small and grey. */
export type ReportStat = { label: string; value: string; unit?: string; hint?: string | null; tone?: 'green' | 'amber' | 'red' | 'violet' };

/**
 * One report of a menu as a card (mẫu 28/11): icon, title, description and arrow to open it, a grey strip with its
 * figures for the period (mẫu 28/11 b: each figure in its own equal column, unit small, one hint line), and its tabs as chips. `badge` flags something to look at (e.g. "2 quá hạn").
 */
const props = withDefaults(
    defineProps<{
        link: ReportLink;
        target: (l: ReportLink, by?: string) => RouteLocationRaw;
        tone?: 'blue' | 'green' | 'amber' | 'red' | 'violet' | 'slate' | 'rose';
        stats?: ReportStat[];
        badge?: string | null;
        badgeTone?: 'red' | 'amber';
    }>(),
    { tone: 'blue', stats: () => [], badge: null, badgeTone: 'red' },
);
const statTones: Record<string, string> = { green: 'text-emerald-600', amber: 'text-amber-600', red: 'text-red-600', violet: 'text-violet-600' };
/** "53.474.198 đ" → ["53.474.198", "đ"]; "12,4%" → ["12,4", "%"]. */
function split(s: ReportStat): [string, string] {
    if (s.unit !== undefined) return [s.value, s.unit];
    const m = /^([−+-]?[\d.,]+)\s*(\D.*)?$/.exec(s.value.trim());
    return m ? [m[1]!, (m[2] ?? '').trim()] : [s.value, ''];
}
const cells = computed(() => props.stats.map((s) => ({ ...s, parts: split(s) })));
const icons: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-emerald-50 text-emerald-600',
    amber: 'bg-amber-50 text-amber-600',
    red: 'bg-red-50 text-red-500',
    violet: 'bg-violet-50 text-violet-600',
    slate: 'bg-slate-100 text-slate-600',
    rose: 'bg-pink-50 text-pink-600',
};
</script>

<template>
    <article class="flex flex-col rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]" :data-testid="`report-card-${props.link.title}`">
        <div class="flex items-start gap-4">
            <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', icons[tone])"><component :is="link.icon" class="size-5" :stroke-width="1.9" /></span>
            <div class="min-w-0 flex-1">
                <RouterLink :to="target(link)" class="flex flex-wrap items-center gap-2 text-[16px] font-semibold text-slate-900 hover:text-blue-600">
                    {{ link.title }}
                    <span v-if="badge" :class="cn('rounded-md px-1.5 py-0.5 text-[11px] font-semibold', badgeTone === 'red' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700')">{{ badge }}</span>
                </RouterLink>
                <p class="mt-0.5 text-[13px] text-slate-500">{{ link.description }}</p>
            </div>
            <RouterLink :to="target(link)" class="grid size-8 shrink-0 place-items-center rounded-[9px] border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-blue-600" aria-hidden="true" tabindex="-1"><ArrowRight class="size-4" /></RouterLink>
        </div>
        <dl
            v-if="cells.length"
            class="mt-4 grid grid-cols-2 gap-y-1 rounded-[12px] bg-slate-50 py-1 sm:grid-cols-[repeat(var(--n),minmax(0,1fr))] sm:divide-x sm:divide-slate-200/70"
            :style="{ '--n': cells.length }"
            data-testid="report-card-stats"
        >
            <div v-for="s in cells" :key="s.label" class="min-w-0 px-4 py-3">
                <dt class="truncate text-[13px] text-slate-500">{{ s.label }}</dt>
                <dd :class="cn('mt-1 truncate whitespace-nowrap text-[20px] font-bold leading-7 tracking-tight tabular-nums', s.tone ? statTones[s.tone] : 'text-slate-900')">
                    {{ s.parts[0] }}<span v-if="s.parts[1]" class="ml-1 text-[13px] font-medium text-slate-400">{{ s.parts[1] }}</span>
                </dd>
                <dd v-if="s.hint" class="mt-0.5 truncate text-[12px] text-slate-500" :title="s.hint">{{ s.hint }}</dd>
            </div>
        </dl>
        <div v-if="link.chips" class="mt-auto flex flex-wrap gap-2 pt-4">
            <RouterLink v-for="c in link.chips" :key="c.by" :to="target(link, c.by)" class="rounded-[9px] border border-slate-200 px-3 py-1.5 text-[13px] text-slate-700 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">{{ c.label }}</RouterLink>
        </div>
    </article>
</template>
