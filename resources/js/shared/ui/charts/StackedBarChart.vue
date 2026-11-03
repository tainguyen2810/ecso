<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney, formatNumber } from '@/shared/lib/utils';

/**
 * Vertical stacked bars without a chart library: one bar per label, one segment per series.
 * Axis ticks use short money ("10 tr"); the tooltip shows each series' exact value.
 */
const props = withDefaults(
    defineProps<{ labels: string[]; series: { key: string; label: string; color: string; values: number[] }[]; height?: number }>(),
    { height: 220 },
);

const totals = computed(() => props.labels.map((_, i) => props.series.reduce((s, x) => s + (x.values[i] ?? 0), 0)));
const max = computed(() => {
    const top = Math.max(...totals.value, 0);
    if (top <= 0) return 1;
    const step = 10 ** Math.floor(Math.log10(top));
    return Math.ceil(top / step) * step;
});
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => f * max.value));

function short(v: number): string {
    if (v >= 1e9) return `${formatNumber(v / 1e9)} tỷ`;
    if (v >= 1e6) return `${formatNumber(v / 1e6)} tr`;
    if (v >= 1e3) return `${formatNumber(v / 1e3)} k`;
    return formatNumber(v);
}
function tip(i: number): string {
    return [`${props.labels[i]}: ${formatMoney(totals.value[i])}`, ...props.series.filter((s) => s.values[i]).map((s) => `${s.label}: ${formatMoney(s.values[i])}`)].join('\n');
}
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <span v-for="s in series" :key="s.key" class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.label }}</span>
        </div>
        <div class="relative ml-12 border-b" :style="{ height: `${height}px` }">
            <div v-for="t in ticks" :key="t" class="absolute inset-x-0 border-t border-dashed" :style="{ bottom: `${(t / max) * 100}%` }">
                <span class="absolute -left-12 -top-2 w-10 text-right text-[10px] text-muted-foreground">{{ short(t) }}</span>
            </div>
            <div class="absolute inset-0 flex items-end gap-[3%] px-[1.5%]">
                <div v-for="(l, i) in labels" :key="l" class="flex h-full flex-1 flex-col-reverse" :title="tip(i)">
                    <div v-for="s in series" :key="s.key" :style="{ height: `${((s.values[i] ?? 0) / max) * 100}%`, background: s.color }" class="first:rounded-b-none last:rounded-t-[3px]" />
                </div>
            </div>
        </div>
        <div class="ml-12 mt-2 flex gap-[3%] px-[1.5%] text-[11px] text-muted-foreground">
            <span v-for="l in labels" :key="l" class="flex-1 text-center">{{ l }}</span>
        </div>
    </div>
</template>
