<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import type { DayRow } from './finance';

/**
 * Net revenue recognised per day (bars) and gross profit per day (line, before returns). Hover or tap a day for its
 * figures; the line is left out for members who cannot see profit.
 */
const props = defineProps<{ days: DayRow[]; showProfit: boolean }>();
const active = ref<number | null>(null);

const W = 640;
const H = 200;
const values = computed(() => props.days.flatMap((d) => [d.net_revenue, props.showProfit ? (d.gross_profit ?? 0) : 0]));
const top = computed(() => Math.max(1, ...values.value));
const bottom = computed(() => Math.min(0, ...values.value));
const y = (v: number) => H - ((v - bottom.value) / (top.value - bottom.value)) * H;
const step = computed(() => W / Math.max(1, props.days.length));
const line = computed(() =>
    props.days.map((d, i) => `${i === 0 ? 'M' : 'L'}${(i + 0.5) * step.value},${y(d.gross_profit ?? 0)}`).join(' '),
);
const current = computed(() => (active.value === null ? null : (props.days[active.value] ?? null)));
const short = (day: string) => day.split('-').slice(1).reverse().join('/');
</script>

<template>
    <div>
        <div class="mb-2 flex min-h-5 flex-wrap items-center gap-x-4 gap-y-1 text-xs">
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-primary/70" /> Doanh thu thuần đã ghi nhận</span>
            <span v-if="showProfit" class="flex items-center gap-1.5"><span class="h-0.5 w-4 bg-emerald-600" /> Lợi nhuận gộp (chưa trừ hàng trả)</span>
            <span v-if="current" class="ml-auto tabular-nums" data-testid="chart-tooltip">
                <strong>{{ short(current.day) }}</strong> · {{ formatMoney(current.net_revenue) }} · {{ formatNumber(current.completed_orders) }} đơn
                <template v-if="showProfit"> · lãi gộp {{ formatMoney(current.gross_profit) }}</template>
                · đặt {{ formatNumber(current.orders) }} đơn
            </span>
        </div>
        <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" class="h-52 w-full touch-pan-y" role="img" aria-label="Biểu đồ doanh thu và lợi nhuận theo ngày" data-testid="daily-chart" @mouseleave="active = null">
            <line :x1="0" :x2="W" :y1="y(0)" :y2="y(0)" class="stroke-border" stroke-width="1" vector-effect="non-scaling-stroke" />
            <g v-for="(d, i) in days" :key="d.day">
                <rect
                    :x="i * step + step * 0.15"
                    :width="step * 0.7"
                    :y="Math.min(y(d.net_revenue), y(0))"
                    :height="Math.abs(y(0) - y(d.net_revenue))"
                    rx="2"
                    :class="active === i ? 'fill-primary' : 'fill-primary/60'"
                />
                <rect :x="i * step" :width="step" y="0" :height="H" fill="transparent" @mouseenter="active = i" @click="active = i" @touchstart.passive="active = i" />
            </g>
            <path v-if="showProfit && days.length" :d="line" fill="none" class="stroke-emerald-600" stroke-width="2" vector-effect="non-scaling-stroke" pointer-events="none" />
        </svg>
        <div class="mt-1 flex justify-between text-xs text-muted-foreground">
            <span>{{ days.length ? short(days[0].day) : '' }}</span><span>{{ days.length ? short(days[days.length - 1].day) : '' }}</span>
        </div>
    </div>
</template>
