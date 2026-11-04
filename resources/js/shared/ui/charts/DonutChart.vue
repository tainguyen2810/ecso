<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { formatNumber } from '@/shared/lib/utils';
import { wholePercents } from './percent';

/**
 * Share of a whole (e.g. revenue by channel) with a legend in percent. The whole percents always add up to 100
 * (largest remainder). `showValue` adds the count beside the percent; an item with `to` links to its detail.
 */
const props = defineProps<{ items: { key: string; label: string; color: string; value: number; to?: RouteLocationRaw }[]; showValue?: boolean }>();

const total = computed(() => props.items.reduce((s, i) => s + i.value, 0));
const segments = computed(() => {
    const whole = wholePercents(props.items.map((i) => i.value));
    let offset = 0;
    return props.items.map((i, n) => {
        const pct = total.value > 0 ? (i.value / total.value) * 100 : 0;
        const seg = { ...i, pct, whole: whole[n]!, offset };
        offset += pct;
        return seg;
    });
});
</script>

<template>
    <div class="flex items-center gap-5">
        <svg viewBox="0 0 42 42" class="size-28 shrink-0 -rotate-90" role="img" aria-label="Tỷ trọng">
            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="var(--color-muted)" stroke-width="6" />
            <circle
                v-for="s in segments.filter((x) => x.pct > 0)"
                :key="s.key"
                cx="21"
                cy="21"
                r="15.9155"
                fill="none"
                :stroke="s.color"
                stroke-width="6"
                :stroke-dasharray="`${s.pct} ${100 - s.pct}`"
                :stroke-dashoffset="-s.offset"
            />
        </svg>
        <ul class="flex-1 space-y-1.5 text-xs text-muted-foreground">
            <li v-for="s in segments" :key="s.key">
                <component :is="s.to ? RouterLink : 'div'" :to="s.to" :class="['flex items-center', s.to && 'rounded hover:text-primary']" :data-testid="`donut-${s.key}`">
                    <i class="mr-2 size-2.5 shrink-0 rounded-sm" :style="{ background: s.color }" />{{ s.label }}
                    <b v-if="showValue" class="ml-auto pl-3 tabular-nums text-foreground">{{ formatNumber(s.value) }}</b>
                    <span :class="['w-11 shrink-0 text-right tabular-nums', showValue ? '' : 'ml-auto font-bold text-foreground']">{{ s.whole }}%</span>
                </component>
            </li>
        </ul>
    </div>
</template>
