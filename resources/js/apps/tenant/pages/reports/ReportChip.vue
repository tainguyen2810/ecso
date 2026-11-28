<script setup lang="ts">
import { cn } from '@/shared/lib/utils';

/** Small pill under a report figure: a change (up/down), a warning (amber), a plain fact, or one on the blue card. */
withDefaults(defineProps<{ tone?: 'up' | 'down' | 'amber' | 'red' | 'plain' | 'onblue' }>(), { tone: 'plain' });
const tones: Record<string, string> = {
    up: 'bg-emerald-50 text-emerald-700',
    down: 'bg-red-50 text-red-600',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-600',
    plain: 'bg-slate-100 text-slate-700',
    onblue: 'bg-white/20 text-white',
};
</script>

<template>
    <span :class="cn('inline-flex items-center gap-0.5 whitespace-nowrap rounded-md px-2 py-0.5 font-medium tabular-nums', tones[tone])"><slot /></span>
</template>
