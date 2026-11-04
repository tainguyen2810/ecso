<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney, formatNumber } from '@/shared/lib/utils';

/**
 * Vertical stacked bars without a chart library: one bar per label, one segment per series. Positive values stack
 * up from 0, negative values (a loss) stack down below the 0 line, so a bar never hides or overstates a value.
 * Axis ticks use short money ("10 tr"); the tooltip shows each series' exact value, or `tips[i]` when given.
 * `clickable` makes each bar emit `select` with its index (drill-down to that day).
 */
const props = withDefaults(
    defineProps<{ labels: string[]; series: { key: string; label: string; color: string; values: number[] }[]; height?: number; tips?: string[]; clickable?: boolean }>(),
    { height: 220, tips: undefined, clickable: false },
);
const emit = defineEmits<{ select: [index: number] }>();

const up = computed(() => props.labels.map((_, i) => props.series.reduce((s, x) => s + Math.max(0, x.values[i] ?? 0), 0)));
const down = computed(() => props.labels.map((_, i) => props.series.reduce((s, x) => s + Math.min(0, x.values[i] ?? 0), 0)));
const totals = computed(() => props.labels.map((_, i) => up.value[i]! + down.value[i]!));

function nice(v: number): number {
    if (v <= 0) return 0;
    const step = 10 ** Math.floor(Math.log10(v));
    return Math.ceil(v / step) * step;
}
const max = computed(() => nice(Math.max(...up.value, 0)) || (Math.min(...down.value, 0) < 0 ? 0 : 1));
const min = computed(() => -nice(-Math.min(...down.value, 0)));
const span = computed(() => max.value - min.value || 1);
/** Distance from the bottom of the plot to the 0 line, in %. */
const zero = computed(() => (-min.value / span.value) * 100);
const pct = (v: number) => (Math.abs(v) / span.value) * 100;

const ticks = computed(() => {
    const t = [0, 0.25, 0.5, 0.75, 1].map((f) => f * max.value);
    if (min.value < 0) t.push(min.value / 2, min.value);
    return [...new Set(t)];
});
// Many bars (30 days): thinner gaps and every n-th label, so the axis never overflows.
const dense = computed(() => props.labels.length > 14);
const labelStep = computed(() => Math.ceil(props.labels.length / 10));

function short(v: number): string {
    const sign = v < 0 ? '−' : '';
    const a = Math.abs(v);
    if (a >= 1e9) return `${sign}${formatNumber(a / 1e9)} tỷ`;
    if (a >= 1e6) return `${sign}${formatNumber(a / 1e6)} tr`;
    if (a >= 1e3) return `${sign}${formatNumber(a / 1e3)} k`;
    return sign + formatNumber(a);
}
function tip(i: number): string {
    if (props.tips?.[i]) return props.tips[i]!;
    return [`${props.labels[i]}: ${formatMoney(totals.value[i]!)}`, ...props.series.filter((s) => s.values[i]).map((s) => `${s.label}: ${formatMoney(s.values[i]!)}`)].join('\n');
}
const positive = (i: number) => props.series.filter((s) => (s.values[i] ?? 0) > 0);
const negative = (i: number) => props.series.filter((s) => (s.values[i] ?? 0) < 0);
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <span v-for="s in series" :key="s.key" class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.label }}</span>
        </div>
        <div class="relative ml-12" :style="{ height: `${height}px` }">
            <div v-for="t in ticks" :key="t" :class="['absolute inset-x-0 border-t', t === 0 ? 'border-slate-400' : 'border-dashed']" :style="{ bottom: `${((t - min) / span) * 100}%` }">
                <span class="absolute -left-12 -top-2 w-10 text-right text-[10px] text-muted-foreground">{{ short(t) }}</span>
            </div>
            <div :class="['absolute inset-0 flex px-[1.5%]', dense ? 'gap-[0.8%]' : 'gap-[3%]']">
                <div
                    v-for="(l, i) in labels"
                    :key="l"
                    :class="['relative h-full min-w-0 flex-1', clickable && 'cursor-pointer hover:opacity-80']"
                    :title="tip(i)"
                    data-testid="bar"
                    @click="clickable && emit('select', i)"
                >
                    <div class="absolute inset-x-0 flex flex-col-reverse" :style="{ bottom: `${zero}%` }">
                        <div v-for="s in positive(i)" :key="s.key" :style="{ height: `${(pct(s.values[i]!) / 100) * height}px`, background: s.color }" class="last:rounded-t-[3px]" />
                    </div>
                    <div class="absolute inset-x-0 flex flex-col" :style="{ top: `${100 - zero}%` }">
                        <div v-for="s in negative(i)" :key="s.key" :style="{ height: `${(pct(s.values[i]!) / 100) * height}px`, background: s.color }" class="last:rounded-b-[3px]" />
                    </div>
                </div>
            </div>
        </div>
        <div :class="['ml-12 mt-2 flex px-[1.5%] text-[11px] text-muted-foreground', dense ? 'gap-[0.8%]' : 'gap-[3%]']">
            <span v-for="(l, i) in labels" :key="l" class="min-w-0 flex-1 overflow-visible whitespace-nowrap text-center">{{ i % labelStep === 0 ? l : '' }}</span>
        </div>
    </div>
</template>
