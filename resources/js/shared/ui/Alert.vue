<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/shared/lib/utils';
import type { Tone } from '@/shared/lib/tones';

/** Inline notice above or inside a card: light tint, coloured icon, normal text colour. */
const props = withDefaults(defineProps<{ icon?: Component; tone?: Tone; class?: string }>(), { tone: 'blue' });

const tones: Record<Tone, string> = {
    blue: 'border-blue-100 bg-blue-50/70 [&>svg]:text-blue-500',
    green: 'border-emerald-100 bg-emerald-50/70 [&>svg]:text-emerald-500',
    amber: 'border-amber-200 bg-amber-50/70 [&>svg]:text-amber-500',
    red: 'border-rose-200 bg-rose-50/70 text-rose-900 [&>svg]:text-rose-500',
    slate: 'border-border bg-muted/50 [&>svg]:text-muted-foreground',
};
</script>

<template>
    <div :class="cn('flex items-start gap-2.5 rounded-lg border px-4 py-3 text-sm', tones[props.tone], props.class)">
        <component :is="icon" v-if="icon" class="mt-0.5 size-4 shrink-0" />
        <div class="min-w-0 flex-1"><slot /></div>
    </div>
</template>
