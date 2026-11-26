<script setup lang="ts">
import { computed } from 'vue';
import { Truck } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';

/**
 * A carrier's own logo (public/images/carriers/<key>.webp) inside a fixed box. Logos are sized by area, so a long
 * wordmark and a compact one look equally heavy in a column. Carriers without a logo get a truck.
 */
const props = withDefaults(defineProps<{ carrier: string | null; name?: string | null; width?: number; height?: number; class?: string }>(), { width: 88, height: 28 });

/** width / height of each logo file. */
const ratios: Record<string, number> = { ghn: 5.33, viettelpost: 2.6, jt: 4.7, ghtk: 3.88, spx: 2.5 };

const size = computed(() => {
    const r = props.carrier ? ratios[props.carrier] : undefined;
    if (!r) return null;
    let w = Math.sqrt(props.width * props.height * 0.5 * r);
    let h = w / r;
    if (w > props.width) [w, h] = [props.width, props.width / r];
    if (h > props.height) [w, h] = [props.height * r, props.height];
    return { width: `${w.toFixed(1)}px`, height: `${h.toFixed(1)}px` };
});
</script>

<template>
    <span :class="cn('grid shrink-0 place-items-center', props.class)" :style="{ width: `${width}px`, height: `${height}px` }" :title="name ?? carrier ?? undefined">
        <img v-if="size" :src="`/images/carriers/${carrier}.webp`" :alt="name ?? carrier ?? ''" :style="size" loading="lazy" />
        <span v-else class="grid size-7 place-items-center rounded-full bg-slate-100 text-slate-500"><Truck class="size-4" /></span>
    </span>
</template>
