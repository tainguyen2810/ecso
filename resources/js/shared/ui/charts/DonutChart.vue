<script setup lang="ts">
import { computed } from 'vue';

/** Share of a whole (e.g. revenue by channel) with a legend in percent. */
const props = defineProps<{ items: { key: string; label: string; color: string; value: number }[] }>();

const total = computed(() => props.items.reduce((s, i) => s + i.value, 0));
const segments = computed(() => {
    let offset = 0;
    return props.items.map((i) => {
        const pct = total.value > 0 ? (i.value / total.value) * 100 : 0;
        const seg = { ...i, pct, offset };
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
                v-for="s in segments"
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
            <li v-for="s in segments" :key="s.key" class="flex items-center">
                <i class="mr-2 size-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.label }}
                <b class="ml-auto tabular-nums text-foreground">{{ Math.round(s.pct) }}%</b>
            </li>
        </ul>
    </div>
</template>
