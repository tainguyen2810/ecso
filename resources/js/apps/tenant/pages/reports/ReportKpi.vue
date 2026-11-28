<script setup lang="ts">
import type { Component } from 'vue';
import InfoTip from '@/shared/ui/InfoTip.vue';
import { cn, formatNumber } from '@/shared/lib/utils';

/**
 * Headline figure of a report menu (mẫu 28/11): label with an explanation, icon top right, big number with its unit,
 * a row of chips and one line of context. `primary` is the blue card that leads the row.
 */
withDefaults(
    defineProps<{
        label: string;
        tip?: string;
        tipTitle?: string;
        icon: Component;
        /** Colour of the icon square and, unless `plain`, of the figure. */
        tone?: 'blue' | 'green' | 'amber' | 'red' | 'violet' | 'slate';
        value: number | null;
        /** Figure already written out (e.g. "3 lô"), shown instead of `value`. */
        text?: string;
        unit?: string;
        primary?: boolean;
        plain?: boolean;
    }>(),
    { tone: 'slate', unit: 'đ', primary: false, plain: false, tip: undefined, tipTitle: undefined, text: undefined },
);

const icons: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-emerald-50 text-emerald-600',
    amber: 'bg-amber-50 text-amber-600',
    red: 'bg-red-50 text-red-500',
    violet: 'bg-violet-50 text-violet-600',
    slate: 'bg-slate-100 text-slate-600',
};
const figures: Record<string, string> = {
    blue: 'text-blue-600',
    green: 'text-emerald-600',
    amber: 'text-amber-600',
    red: 'text-red-600',
    violet: 'text-violet-600',
    slate: 'text-slate-900',
};
</script>

<template>
    <div
        :class="
            cn(
                'relative overflow-hidden rounded-[14px] p-5',
                primary ? 'bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-[0_8px_24px_-8px_rgba(37,99,235,0.55)]' : 'border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]',
            )
        "
    >
        <div class="flex items-start justify-between gap-3">
            <p :class="cn('flex items-center gap-1 text-[14px]', primary ? 'text-blue-100' : 'text-slate-500')">
                {{ label }}
                <InfoTip v-if="tip" :title="tipTitle ?? label" :class="primary ? 'text-blue-100' : undefined">{{ tip }}</InfoTip>
            </p>
            <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', primary ? 'bg-white/15 text-white' : icons[tone])"><component :is="icon" class="size-5" :stroke-width="1.9" /></span>
        </div>
        <p :class="cn('-mt-1 whitespace-nowrap text-[28px] font-bold leading-none tabular-nums tracking-tight', primary ? 'text-white' : plain ? 'text-slate-900' : figures[tone])" data-testid="kpi-value">
            <template v-if="text !== undefined">{{ text }}</template>
            <template v-else-if="value === null">—</template>
            <template v-else>{{ formatNumber(value) }}<span :class="cn('ml-1 text-[16px] font-semibold', primary ? 'text-blue-200' : 'text-slate-400')">{{ unit }}</span></template>
        </p>
        <div v-if="$slots.chips" class="mt-3 flex flex-wrap items-center gap-1.5 text-xs"><slot name="chips" /></div>
        <p v-if="$slots.default" :class="cn('relative z-10 mt-2.5 text-[13px]', primary ? 'text-blue-100' : 'text-slate-500')"><slot /></p>
        <slot name="decor" />
    </div>
</template>
